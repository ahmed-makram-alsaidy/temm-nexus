/*
 * ll-sh — Landlock-sandboxed shell for the managed OpenCode runtime.
 *
 * TEMM Nexus 0.5.0 final hardening: a shell spawned for an agent session
 * must see ONLY its own assigned workspace. opencode spawns this wrapper
 * (configured as the runtime's `shell`) with the session workspace as the
 * working directory. The wrapper:
 *
 *   1. resolves its working directory and REFUSES to run unless it is
 *      beneath the TEMM agent-workspaces root (fail closed);
 *   2. scrubs the inherited environment down to a safe whitelist (the
 *      server process environment carries the runtime auth password and
 *      provider configuration that sessions must never read);
 *   3. applies a Landlock ruleset allowing only:
 *        - full read/write/execute beneath the session workspace,
 *        - read/write beneath the worktree's own gitdir (so `git status`
 *          works; the shared repository object store is read-only),
 *        - read/execute on system paths (/usr, /bin, /sbin, /lib, /etc),
 *        - read on /proc, read/write on /dev and /tmp;
 *      everything else — sibling workspaces, app_storage, HOME, mounts —
 *      is denied by default;
 *   4. execs the real shell. If Landlock is unavailable (kernel ABI < 3,
 *      i.e. kernel < 6.2) the wrapper refuses to run: no sandbox, no shell.
 *
 * Cross-directory rename/link (Landlock REFER) is intentionally NOT
 * granted, so files cannot be moved out of the workspace into /tmp.
 * Symlink escapes are inherently blocked: Landlock evaluates real paths.
 */
#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#include <sys/prctl.h>
#include <sys/stat.h>
#include <sys/syscall.h>

/* Landlock ABI v1 bits (kernel 5.13+), defined inline so no kernel
 * headers are needed at build time. */
#ifndef LANDLOCK_ACCESS_FS_EXECUTE
#define LANDLOCK_ACCESS_FS_EXECUTE 0x0001ULL
#define LANDLOCK_ACCESS_FS_WRITE_FILE 0x0002ULL
#define LANDLOCK_ACCESS_FS_READ_FILE 0x0004ULL
#define LANDLOCK_ACCESS_FS_READ_DIR 0x0008ULL
#define LANDLOCK_ACCESS_FS_REMOVE_DIR 0x0010ULL
#define LANDLOCK_ACCESS_FS_REMOVE_FILE 0x0020ULL
#define LANDLOCK_ACCESS_FS_MAKE_CHAR 0x0040ULL
#define LANDLOCK_ACCESS_FS_MAKE_DIR 0x0080ULL
#define LANDLOCK_ACCESS_FS_MAKE_SYM 0x0100ULL
#define LANDLOCK_ACCESS_FS_MAKE_REG 0x0200ULL
#define LANDLOCK_ACCESS_FS_MAKE_BLOCK 0x0400ULL
#define LANDLOCK_ACCESS_FS_MAKE_FIFO 0x0800ULL
#endif
#ifndef LANDLOCK_ACCESS_FS_REFER
#define LANDLOCK_ACCESS_FS_REFER 0x1000ULL /* ABI v2 — deliberately NOT granted */
#endif
#ifndef LANDLOCK_ACCESS_FS_TRUNCATE
#define LANDLOCK_ACCESS_FS_TRUNCATE 0x2000ULL /* ABI v3 */
#endif

#ifndef LANDLOCK_RULE_PATH_BENEATH
#define LANDLOCK_RULE_PATH_BENEATH 1
#endif
#ifndef LANDLOCK_CREATE_RULESET_VERSION
#define LANDLOCK_CREATE_RULESET_VERSION 0x0001
#endif

struct landlock_ruleset_attr_v3 {
    unsigned long long handled_access_fs;
};

struct landlock_path_beneath_attr {
    unsigned long long allowed_access;
    int parent_fd;
};

static const char *WORKSPACES_ROOT = "/var/www/html/storage/app/private/agent-workspaces";

static int ruleset_fd = -1;

static void add_rule(const char *path, unsigned long long access)
{
    struct landlock_path_beneath_attr rule;
    int fd = open(path, O_PATH | O_CLOEXEC);

    if (fd < 0) {
        return; /* path absent in this image — nothing to grant */
    }
    memset(&rule, 0, sizeof(rule));
    rule.allowed_access = access;
    rule.parent_fd = fd;
    if (syscall(SYS_landlock_add_rule, ruleset_fd, LANDLOCK_RULE_PATH_BENEATH, &rule, 0) != 0) {
        fprintf(stderr, "ll-sh: landlock_add_rule(%s) failed: %s\n", path, strerror(errno));
        close(fd);
        exit(126);
    }
    close(fd);
}

static char *xrealpath(const char *path, char *resolved)
{
    return realpath(path, resolved);
}

/* Scrub the environment down to a fixed whitelist. Returns a NULL-
 * terminated environ for execve. */
static char **build_clean_env(void)
{
    static const char *keep[] = {
        "PATH", "HOME", "TERM", "LANG", "LC_ALL", "LC_CTYPE",
        "TMPDIR", "USER", "LOGNAME", "SHELL", "HOSTNAME",
    };
    char **env;
    size_t n = 0;

    /* +5: TMPDIR fallback, HOME pin, npm cache, NULL, spare. */
    env = calloc(sizeof(keep) / sizeof(keep[0]) + 5, sizeof(char *));
    for (size_t i = 0; i < sizeof(keep) / sizeof(keep[0]); i++) {
        const char *v = getenv(keep[i]);
        if (v != NULL) {
            size_t len = strlen(keep[i]) + strlen(v) + 2;
            char *entry = malloc(len);
            snprintf(entry, len, "%s=%s", keep[i], v);
            env[n++] = entry;
        }
    }
    if (getenv("TMPDIR") == NULL) {
        env[n++] = strdup("TMPDIR=/tmp");
    }
    /* The sandbox deliberately seals the runtime's own HOME (/home/agent:
     * auth.json and opencode state). Tools that need a writable home
     * (git, npm) get /tmp instead. */
    {
        /* drop any earlier HOME entry, then pin it */
        for (size_t i = 0; env[i] != NULL; i++) {
            if (strncmp(env[i], "HOME=", 5) == 0) {
                env[i] = strdup("HOME=/tmp");
            }
        }
        if (getenv("HOME") == NULL) {
            env[n++] = strdup("HOME=/tmp");
        }
        env[n++] = strdup("npm_config_cache=/tmp/.npm");
    }
    env[n] = NULL;
    return env;
}

int main(int argc, char **argv)
{
    char cwd[4096];
    char resolved_root[4096];
    char resolved_cwd[4096];
    char resolved_gitdir[4096];
    char resolved_common[4096];
    char line[4096];
    unsigned long long full, ro, abi;
    struct landlock_ruleset_attr_v3 attr;
    char **env;

    if (getcwd(cwd, sizeof(cwd)) == NULL) {
        fprintf(stderr, "ll-sh: no working directory\n");
        return 126;
    }

    if (xrealpath(WORKSPACES_ROOT, resolved_root) == NULL ||
        xrealpath(cwd, resolved_cwd) == NULL) {
        fprintf(stderr, "ll-sh: cannot resolve paths\n");
        return 126;
    }

    /* Fail closed: only shells spawned beneath the workspaces root. */
    if (strncmp(resolved_cwd, resolved_root, strlen(resolved_root)) != 0 ||
        (resolved_cwd[strlen(resolved_root)] != '\0' &&
         resolved_cwd[strlen(resolved_root)] != '/')) {
        fprintf(stderr,
                "ll-sh: refusing to run outside the agent workspaces root (%s)\n",
                resolved_cwd);
        return 126;
    }

    /* Landlock ABI probe: kernel 6.2+ (ABI >= 3) required so TRUNCATE can
     * be handled. Anything less = fail closed. */
    abi = syscall(SYS_landlock_create_ruleset, NULL, 0, LANDLOCK_CREATE_RULESET_VERSION);
    if ((long) abi < 3) {
        fprintf(stderr,
                "ll-sh: Landlock ABI %lu too old (need >= 3); refusing unsandboxed shell\n",
                abi);
        return 126;
    }

    full = LANDLOCK_ACCESS_FS_EXECUTE | LANDLOCK_ACCESS_FS_WRITE_FILE |
           LANDLOCK_ACCESS_FS_READ_FILE | LANDLOCK_ACCESS_FS_READ_DIR |
           LANDLOCK_ACCESS_FS_REMOVE_DIR | LANDLOCK_ACCESS_FS_REMOVE_FILE |
           LANDLOCK_ACCESS_FS_MAKE_CHAR | LANDLOCK_ACCESS_FS_MAKE_DIR |
           LANDLOCK_ACCESS_FS_MAKE_SYM | LANDLOCK_ACCESS_FS_MAKE_REG |
           LANDLOCK_ACCESS_FS_MAKE_BLOCK | LANDLOCK_ACCESS_FS_MAKE_FIFO |
           LANDLOCK_ACCESS_FS_TRUNCATE;
    ro = LANDLOCK_ACCESS_FS_READ_FILE | LANDLOCK_ACCESS_FS_READ_DIR |
         LANDLOCK_ACCESS_FS_EXECUTE;

    memset(&attr, 0, sizeof(attr));
    attr.handled_access_fs = full; /* everything else is denied by default */

    ruleset_fd = (int) syscall(SYS_landlock_create_ruleset, &attr, sizeof(attr), 0);
    if (ruleset_fd < 0) {
        fprintf(stderr, "ll-sh: landlock_create_ruleset failed: %s\n", strerror(errno));
        return 126;
    }

    /* The session workspace: full access. */
    add_rule(resolved_cwd, full);

    /* Worktree git metadata so `git status`/`git diff` work in-session.
     * The worktree's private gitdir gets read/write; the shared common
     * store (objects, config, refs) is READ-ONLY — a shell cannot commit
     * into the authoritative repository. */
    snprintf(line, sizeof(line), "%s/.git", resolved_cwd);
    {
        FILE *f = fopen(line, "r");
        if (f != NULL && fgets(line, sizeof(line), f) != NULL &&
            strncmp(line, "gitdir:", 7) == 0) {
            char *p = line + 7;
            while (*p == ' ' || *p == '\t') {
                p++;
            }
            p[strcspn(p, "\r\n")] = '\0';
            if (xrealpath(p, resolved_gitdir) != NULL) {
                add_rule(resolved_gitdir, full);
                snprintf(line, sizeof(line), "%s/commondir", resolved_gitdir);
                FILE *cf = fopen(line, "r");
                if (cf != NULL && fgets(line, sizeof(line), cf) != NULL) {
                    char *q = line;
                    while (*q == ' ' || *q == '\t') {
                        q++;
                    }
                    q[strcspn(q, "\r\n")] = '\0';
                    char common[4096];
                    snprintf(common, sizeof(common), "%s/%s", resolved_gitdir, q);
                    if (xrealpath(common, resolved_common) != NULL) {
                        add_rule(resolved_common, ro);
                    }
                    fclose(cf);
                }
            }
        }
        if (f != NULL) {
            fclose(f);
        }
    }

    /* System paths: read/execute only. */
    add_rule("/usr", ro);
    add_rule("/bin", ro);
    add_rule("/sbin", ro);
    add_rule("/lib", ro);
    add_rule("/lib64", ro);
    add_rule("/etc", ro);

    /* Device + proc + tmp. */
    add_rule("/dev", LANDLOCK_ACCESS_FS_READ_FILE | LANDLOCK_ACCESS_FS_READ_DIR |
                         LANDLOCK_ACCESS_FS_WRITE_FILE);
    add_rule("/proc", LANDLOCK_ACCESS_FS_READ_FILE | LANDLOCK_ACCESS_FS_READ_DIR);
    add_rule("/tmp", full);

    if (prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0) {
        fprintf(stderr, "ll-sh: prctl(NO_NEW_PRIVS) failed: %s\n", strerror(errno));
        return 126;
    }

    if (syscall(SYS_landlock_restrict_self, ruleset_fd, 0) != 0) {
        fprintf(stderr, "ll-sh: landlock_restrict_self failed: %s\n", strerror(errno));
        return 126;
    }

    env = build_clean_env();
    execve("/bin/bash", argv, env);

    /* execve failed — do NOT fall back to an unsandboxed anything. */
    fprintf(stderr, "ll-sh: execve(/bin/bash) failed: %s\n", strerror(errno));
    return 126;
}
