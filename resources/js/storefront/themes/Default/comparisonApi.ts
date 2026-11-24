const csrf = () =>
  decodeURIComponent(
    document.cookie
      .split('; ')
      .find((row) => row.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '',
  );

type Reply = { ids: string[]; max: number };

/** One call that changes the visitor's comparison list; a refused change returns the server's message. */
export async function comparisonRequest(
  method: 'PUT' | 'DELETE',
  url: string,
): Promise<{ ok: true; data: Reply } | { ok: false; message: string }> {
  try {
    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
      const first = Object.values(json.errors ?? {})[0] as string[] | undefined;
      return { ok: false, message: first?.[0] ?? json.message ?? '' };
    }
    return { ok: true, data: json as Reply };
  } catch {
    return { ok: false, message: '' };
  }
}
