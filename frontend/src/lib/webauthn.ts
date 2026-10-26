/**
 * Passkeys (WebAuthn). The API sends options with base64url strings (AuthController / SecurityController);
 * these helpers turn them into the ArrayBuffers navigator.credentials wants and serialise the result back.
 */

export const passkeysSupported = () => typeof window !== 'undefined' && 'PublicKeyCredential' in window && Boolean(navigator.credentials);

function toBuf(s: string): ArrayBuffer {
  const b64 = s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4);
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out.buffer;
}

function toB64u(buf: ArrayBuffer | null): string {
  if (!buf) return '';
  let bin = '';
  for (const b of new Uint8Array(buf)) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

interface Descriptor { type: 'public-key'; id: string; transports?: string[] }

export interface CreationOptionsJSON {
  challenge: string;
  rp: { id: string; name: string };
  user: { id: string; name: string; displayName: string };
  pubKeyCredParams: { type: 'public-key'; alg: number }[];
  timeout?: number;
  attestation?: AttestationConveyancePreference;
  authenticatorSelection?: AuthenticatorSelectionCriteria;
  excludeCredentials?: Descriptor[];
}

export interface RequestOptionsJSON {
  challenge: string;
  rpId: string;
  timeout?: number;
  userVerification?: UserVerificationRequirement;
  allowCredentials?: Descriptor[];
}

const descriptors = (list?: Descriptor[]) =>
  (list ?? []).map((d) => ({ type: d.type, id: toBuf(d.id), transports: d.transports as AuthenticatorTransport[] | undefined }));

/** Turn the browser's cancel/timeout errors into something readable. */
function friendly(err: unknown): Error {
  if (err instanceof DOMException) {
    if (err.name === 'NotAllowedError') return new Error('The passkey prompt was cancelled or timed out.');
    if (err.name === 'InvalidStateError') return new Error('This device already has a passkey for your account.');
    if (err.name === 'SecurityError') return new Error('Passkeys need a secure (https) address.');
  }
  return err instanceof Error ? err : new Error('Passkey failed.');
}

export async function createPasskey(o: CreationOptionsJSON) {
  let cred: PublicKeyCredential;
  try {
    cred = (await navigator.credentials.create({
      publicKey: { ...o, challenge: toBuf(o.challenge), user: { ...o.user, id: toBuf(o.user.id) }, excludeCredentials: descriptors(o.excludeCredentials) },
    })) as PublicKeyCredential;
  } catch (e) { throw friendly(e); }
  const r = cred.response as AuthenticatorAttestationResponse;
  return {
    id: cred.id,
    rawId: toB64u(cred.rawId),
    type: cred.type,
    response: {
      clientDataJSON: toB64u(r.clientDataJSON),
      attestationObject: toB64u(r.attestationObject),
      transports: typeof r.getTransports === 'function' ? r.getTransports() : [],
    },
  };
}

export async function getPasskey(o: RequestOptionsJSON) {
  let cred: PublicKeyCredential;
  try {
    cred = (await navigator.credentials.get({
      publicKey: { ...o, challenge: toBuf(o.challenge), allowCredentials: descriptors(o.allowCredentials) },
    })) as PublicKeyCredential;
  } catch (e) { throw friendly(e); }
  const r = cred.response as AuthenticatorAssertionResponse;
  return {
    id: cred.id,
    rawId: toB64u(cred.rawId),
    type: cred.type,
    response: {
      clientDataJSON: toB64u(r.clientDataJSON),
      authenticatorData: toB64u(r.authenticatorData),
      signature: toB64u(r.signature),
      userHandle: toB64u(r.userHandle),
    },
  };
}
