// Line chart for the sales card. The SVG stretches to the card width, so lines
// use non-scaling strokes and the axis labels are positioned in HTML.
const width = 300;
const height = 100;

function points(values: number[], max: number) {
  return values.map((value, index) => [
    (index / (values.length - 1)) * width,
    height - (value / max) * height,
  ]);
}

const line = (coordinates: number[][]) =>
  coordinates.map(([x, y], i) => `${i ? 'L' : 'M'}${x} ${y}`).join(' ');

export default function SalesChart({
  current,
  previous,
  days,
  label,
  formatAxis,
  step = 1000,
  every = 7,
}: {
  current: number[];
  previous: number[];
  days: string[];
  label: string;
  formatAxis: (value: number) => string;
  /** Distance between value gridlines. */
  step?: number;
  /** A time label is shown for every n-th point. */
  every?: number;
}) {
  const max = Math.max(
    step,
    Math.ceil(Math.max(...current, ...previous) / step) * step,
  );
  const ticks = Array.from({ length: max / step + 1 }, (_, i) => i * step);
  const now = points(current, max);
  const area = `${line(now)} L${width} ${height} L0 ${height} Z`;
  const labelled = days
    .map((day, index) => ({ day, index }))
    .filter(({ index }) => index % every === 0);

  return (
    <div className="grid grid-cols-[auto_1fr] gap-x-3">
      <div className="relative h-48 w-9 text-right text-[11px] text-on-primary/80 sm:h-56">
        {ticks.map((tick) => (
          <span
            key={tick}
            className="absolute right-0 -translate-y-1/2"
            style={{ top: `${100 - (tick / max) * 100}%` }}
          >
            {formatAxis(tick)}
          </span>
        ))}
      </div>
      <svg
        role="img"
        aria-label={label}
        viewBox={`0 0 ${width} ${height}`}
        preserveAspectRatio="none"
        className="h-48 w-full overflow-visible sm:h-56"
      >
        <defs>
          <linearGradient id="sales-area" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0" stopColor="white" stopOpacity="0.35" />
            <stop offset="1" stopColor="white" stopOpacity="0" />
          </linearGradient>
        </defs>
        {ticks.map((tick) => (
          <line
            key={tick}
            x1="0"
            x2={width}
            y1={height - (tick / max) * height}
            y2={height - (tick / max) * height}
            stroke="white"
            strokeOpacity="0.2"
            vectorEffect="non-scaling-stroke"
          />
        ))}
        <path d={area} fill="url(#sales-area)" />
        <path
          d={line(points(previous, max))}
          fill="none"
          stroke="white"
          strokeOpacity="0.85"
          strokeWidth="1.25"
          strokeDasharray="3 4"
          vectorEffect="non-scaling-stroke"
        />
        <path
          d={line(now)}
          fill="none"
          stroke="white"
          strokeWidth="2"
          strokeLinejoin="round"
          vectorEffect="non-scaling-stroke"
        />
      </svg>
      <div className="relative col-start-2 mt-3 h-4 text-[11px] text-on-primary/80">
        {labelled.map(({ day, index }) => (
          <span
            key={index}
            className={`absolute whitespace-nowrap ${index ? '-translate-x-1/2' : ''}`}
            style={{ left: `${(index / (days.length - 1)) * 100}%` }}
          >
            {day}
          </span>
        ))}
      </div>
    </div>
  );
}
