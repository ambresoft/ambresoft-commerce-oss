import { beforeEach, expect, it, vi } from 'vitest';
import { performPasskey } from '../lib/passkeys';
import { faqRequest } from '../lib/faqRequest';

vi.mock('../lib/faqRequest', () => ({ faqRequest: vi.fn() }));
class Attestation {
  clientDataJSON = Uint8Array.of(1, 2).buffer;
  attestationObject = Uint8Array.of(3, 4).buffer;
  getTransports() {
    return ['internal'];
  }
}
const create = vi.fn();
const get = vi.fn();
const credential = (response: unknown) => ({
  id: 'AQI',
  rawId: Uint8Array.of(1, 2).buffer,
  type: 'public-key',
  response,
  getClientExtensionResults: () => ({}),
});

beforeEach(() => {
  vi.clearAllMocks();
  vi.stubGlobal('isSecureContext', true);
  vi.stubGlobal('PublicKeyCredential', class {});
  vi.stubGlobal('AuthenticatorAttestationResponse', Attestation);
  Object.defineProperty(navigator, 'credentials', {
    configurable: true,
    value: { create, get },
  });
});

it('decodes registration options and submits only the WebAuthn response to the signed invitation', async () => {
  const url = '/admin/register?token=private&signature=signed';
  vi.mocked(faqRequest)
    .mockResolvedValueOnce({
      challenge: 'AQI',
      user: { id: 'AwQ', name: 'staff@example.test', displayName: 'Staff' },
      rp: { id: 'cms.test', name: 'CMS' },
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
      authenticatorSelection: { userVerification: 'required' },
      excludeCredentials: [{ id: 'BQY', type: 'public-key' }],
    })
    .mockResolvedValueOnce({ redirect: '/admin' });
  create.mockResolvedValue(credential(new Attestation()));
  await expect(
    performPasskey(url, url, true, { operation: 'passkey_options' }),
  ).resolves.toEqual({ redirect: '/admin' });
  const options = create.mock.calls[0][0].publicKey;
  expect([...new Uint8Array(options.challenge)]).toEqual([1, 2]);
  expect([...new Uint8Array(options.user.id)]).toEqual([3, 4]);
  expect([...new Uint8Array(options.excludeCredentials[0].id)]).toEqual([5, 6]);
  expect(faqRequest).toHaveBeenLastCalledWith(url, {
    operation: 'passkey_finish',
    credential: {
      id: 'AQI',
      rawId: 'AQI',
      type: 'public-key',
      clientExtensionResults: {},
      response: {
        clientDataJSON: 'AQI',
        attestationObject: 'AwQ',
        transports: ['internal'],
      },
    },
  });
});

it('serializes the assertion and user handle for admin login', async () => {
  vi.mocked(faqRequest)
    .mockResolvedValueOnce({
      challenge: 'AQI',
      rpId: 'cms.test',
      userVerification: 'required',
    })
    .mockResolvedValueOnce({ redirect: '/admin' });
  get.mockResolvedValue(
    credential({
      clientDataJSON: Uint8Array.of(1).buffer,
      authenticatorData: Uint8Array.of(2).buffer,
      signature: Uint8Array.of(3).buffer,
      userHandle: Uint8Array.of(4).buffer,
    }),
  );
  await performPasskey(
    '/admin/passkeys/login/options',
    '/admin/passkeys/login',
  );
  expect(faqRequest).toHaveBeenLastCalledWith(
    '/admin/passkeys/login',
    expect.objectContaining({
      credential: expect.objectContaining({
        response: {
          clientDataJSON: 'AQ',
          authenticatorData: 'Ag',
          signature: 'Aw',
          userHandle: 'BA',
        },
      }),
    }),
  );
});

it('does not post a completion when the device prompt is cancelled or the browser is unsupported', async () => {
  vi.mocked(faqRequest).mockResolvedValueOnce({ challenge: 'AQI' });
  get.mockRejectedValue(new DOMException('Cancelled', 'NotAllowedError'));
  await expect(performPasskey('/options', '/finish')).rejects.toThrow(
    'Cancelled',
  );
  expect(faqRequest).toHaveBeenCalledTimes(1);
  vi.stubGlobal('isSecureContext', false);
  await expect(performPasskey('/options', '/finish')).rejects.toThrow(
    'passkey_unavailable',
  );
  expect(faqRequest).toHaveBeenCalledTimes(1);
});
