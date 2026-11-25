/** Visual companion to required/aria-required; keep accessible labels stable. */
export default function RequiredMark() {
  return (
    <span aria-hidden="true" className="ml-1 text-red-600">
      *
    </span>
  );
}
