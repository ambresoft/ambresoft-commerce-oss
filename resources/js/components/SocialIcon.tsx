/** Simple colored marks for the supported social networks. */
export const socialNetworks = [
  'facebook',
  'instagram',
  'tiktok',
  'linkedin',
  'x',
  'youtube',
  'pinterest',
] as const;
export type SocialNetwork = (typeof socialNetworks)[number];

export default function SocialIcon({
  network,
  image,
  className = 'size-8',
}: {
  network: string;
  image?: string | null;
  className?: string;
}) {
  if (image)
    return <img src={image} alt="" className={className + ' object-contain'} />;
  const text = (label: string, size = 18) => (
    <text
      x="16"
      y="22"
      textAnchor="middle"
      fill="#fff"
      fontSize={size}
      fontWeight="700"
      fontFamily="Arial, sans-serif"
    >
      {label}
    </text>
  );
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 32 32"
      width="32"
      height="32"
      className={className}
    >
      {network === 'facebook' && (
        <>
          <circle cx="16" cy="16" r="16" fill="#1877f2" />
          {text('f', 22)}
        </>
      )}
      {network === 'instagram' && (
        <>
          <rect width="32" height="32" rx="9" fill="#d6249f" />
          <rect
            x="7"
            y="7"
            width="18"
            height="18"
            rx="5.5"
            fill="none"
            stroke="#fff"
            strokeWidth="2"
          />
          <circle
            cx="16"
            cy="16"
            r="4.3"
            fill="none"
            stroke="#fff"
            strokeWidth="2"
          />
          <circle cx="21.6" cy="10.4" r="1.2" fill="#fff" />
        </>
      )}
      {network === 'tiktok' && (
        <>
          <rect width="32" height="32" rx="9" fill="#111" />
          <path
            d="M17 8v11.2a3.2 3.2 0 1 1-3.2-3.2M17 8c.4 2.6 2 4 4.6 4.2"
            fill="none"
            stroke="#fff"
            strokeWidth="2.4"
            strokeLinecap="round"
          />
        </>
      )}
      {network === 'linkedin' && (
        <>
          <rect width="32" height="32" rx="7" fill="#2867b2" />
          {text('in', 16)}
        </>
      )}
      {network === 'x' && (
        <>
          <rect width="32" height="32" rx="9" fill="#111" />
          <path
            d="M9 9l14 14M23 9 9 23"
            stroke="#fff"
            strokeWidth="2.4"
            strokeLinecap="round"
          />
        </>
      )}
      {network === 'youtube' && (
        <>
          <rect y="6" width="32" height="20" rx="6" fill="#e62117" />
          <path d="M13 11.5v9l8-4.5z" fill="#fff" />
        </>
      )}
      {network === 'pinterest' && (
        <>
          <circle cx="16" cy="16" r="16" fill="#e60023" />
          {text('P', 20)}
        </>
      )}
    </svg>
  );
}
