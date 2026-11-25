import { faqRequest } from './faqRequest';

type Options = {
  challenge: string;
  user?: { id: string; name: string; displayName: string };
  excludeCredentials?: { id: string; type: PublicKeyCredentialType }[];
};

function decode(value: string): ArrayBuffer {
  return Uint8Array.from(
    atob(value.replace(/-/g, '+').replace(/_/g, '/')),
    (char) => char.charCodeAt(0),
  ).buffer;
}

function encode(value: ArrayBuffer): string {
  return btoa(String.fromCharCode(...new Uint8Array(value)))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
}

export async function performPasskey(
  optionsUrl: string,
  finishUrl: string,
  create = false,
  data: Record<string, unknown> = {},
) {
  if (!window.isSecureContext || !window.PublicKeyCredential) {
    throw new Error('passkey_unavailable');
  }
  const options = await faqRequest<Options>(optionsUrl, data);
  let credential: PublicKeyCredential | null;
  if (create) {
    credential = (await navigator.credentials.create({
      publicKey: {
        ...options,
        challenge: decode(options.challenge),
        user: { ...options.user!, id: decode(options.user!.id) },
        excludeCredentials: options.excludeCredentials?.map((item) => ({
          ...item,
          id: decode(item.id),
        })),
      } as PublicKeyCredentialCreationOptions,
    })) as PublicKeyCredential | null;
  } else {
    credential = (await navigator.credentials.get({
      publicKey: {
        ...options,
        challenge: decode(options.challenge),
      } as PublicKeyCredentialRequestOptions,
    })) as PublicKeyCredential | null;
  }
  if (!credential) throw new Error('passkey_failed');
  const response = credential.response;
  const body = {
    id: credential.id,
    rawId: encode(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: encode(response.clientDataJSON),
      ...(response instanceof AuthenticatorAttestationResponse
        ? {
            attestationObject: encode(response.attestationObject),
            transports: response.getTransports(),
          }
        : {
            authenticatorData: encode(
              (response as AuthenticatorAssertionResponse).authenticatorData,
            ),
            signature: encode(
              (response as AuthenticatorAssertionResponse).signature,
            ),
            userHandle: (response as AuthenticatorAssertionResponse).userHandle
              ? encode((response as AuthenticatorAssertionResponse).userHandle!)
              : null,
          }),
    },
    clientExtensionResults: credential.getClientExtensionResults(),
  };
  return faqRequest<{ redirect: string }>(finishUrl, {
    operation: 'passkey_finish',
    credential: body,
  });
}
