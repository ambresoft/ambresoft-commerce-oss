export async function faqRequest<T>(url: string, data?: unknown): Promise<T> {
  const token =
    document.cookie
      .split('; ')
      .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
      ?.slice('XSRF-TOKEN='.length) ?? '';
  const response = await fetch(url, {
    method: data === undefined ? 'GET' : 'POST',
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': decodeURIComponent(token),
    },
    ...(data === undefined ? {} : { body: JSON.stringify(data) }),
  });
  if (!response.ok) {
    let message = '';
    if (response.headers.get('content-type')?.includes('application/json')) {
      const body = await response.json();
      message =
        Object.values(body.errors ?? {})
          .flat()
          .join(' ') ||
        body.message ||
        '';
    }
    throw new Error(
      message || (response.status === 429 ? 'rate_limited' : 'request_failed'),
    );
  }
  return response.json() as Promise<T>;
}
