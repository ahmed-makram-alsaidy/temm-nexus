'use client';

/** File upload via the SDK storage module, with ApiError handling. */
import { useState } from 'react';
import { ApiError } from '@platform/backend-sdk';
import { getBrowserClient } from '../lib/backend';

export function UploadButton({ bucket }: { bucket: string }) {
  const [status, setStatus] = useState<string>('');

  async function onFile(file: File) {
    setStatus('Uploading…');
    try {
      const backend = getBrowserClient();
      const obj = await backend.storage.upload(file, { bucket, fileName: file.name });
      setStatus(`Uploaded: ${obj.key}`);
    } catch (err) {
      setStatus(err instanceof ApiError ? `Upload failed: ${err.message} (${err.code})` : 'Upload failed.');
    }
  }

  return (
    <label>
      Upload
      <input
        type="file"
        hidden
        onChange={(e) => {
          const f = e.target.files?.[0];
          if (f) void onFile(f);
        }}
      />
      <span role="status">{status}</span>
    </label>
  );
}
