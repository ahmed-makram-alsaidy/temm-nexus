<?php

namespace App\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * 0.6.0 Phase F (§F14) — presentation formatting for assistant answers.
 *
 * Nexus AI may produce technical, multi-section responses. The engine returns
 * plain text; this presenter renders it as safe, scannable HTML — headings,
 * bullets, code blocks — WITHOUT rewriting the content:
 *
 *   - raw HTML in the model output is stripped (`html_input => 'strip'`),
 *   - unsafe links are refused,
 *   - content inside fenced code blocks is untouched (no soft-break injection),
 *   - a single newline becomes a hard break, so plain-line answers keep their
 *     line structure instead of collapsing into one paragraph.
 *
 * Presentation only — the text itself is never edited destructively.
 */
final class ChatMarkdown
{
    public static function toHtml(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 10,
        ]);

        // Split on fenced code blocks so the hard-break pass only touches
        // prose — code keeps its exact lines.
        $segments = preg_split('/(```.*?```)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        $rendered = '';
        foreach ($segments as $segment) {
            if (str_starts_with((string) $segment, '```')) {
                $rendered .= $segment;

                continue;
            }

            // A lone newline (no blank line around it) becomes a hard break so
            // line-structured answers don't collapse; blank lines still split
            // paragraphs normally.
            $rendered .= preg_replace('/(?<!\n)\n(?!\n)/', "  \n", (string) $segment) ?? (string) $segment;
        }

        return (string) $converter->convert($rendered);
    }
}
