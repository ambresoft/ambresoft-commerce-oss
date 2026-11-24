/** Original Ambresoft mascot artwork; decorative and independent of the form. */
export default function DancingBull() {
  return (
    <div className="bull-mascot hidden min-h-0 flex-1 flex-col items-center justify-center md:flex">
      <svg
        viewBox="0 0 360 380"
        fill="none"
        aria-hidden="true"
        focusable="false"
        className="w-full max-w-64 overflow-visible"
      >
        <circle cx="180" cy="187" r="139" stroke="#90dabd" opacity="0.09" />
        <path
          d="M54 246a140 140 0 0 0 252-118"
          stroke="#90dabd"
          strokeWidth="1.5"
          opacity="0.14"
        />
        <ellipse
          className="bull-shadow"
          cx="180"
          cy="355"
          rx="88"
          ry="9"
          fill="#021c15"
          opacity="0.5"
        />
        <g className="bull-dance">
          <g className="bull-tail">
            <path
              d="M230 256c46 23 39-47 65-37"
              stroke="#d5e9dc"
              strokeWidth="7"
              strokeLinecap="round"
            />
            <path
              d="M291 209c18-3 23 9 19 22-14 1-23-5-19-22Z"
              fill="#78d9ae"
            />
          </g>
          <g className="bull-leg-left">
            <path
              d="m151 274-8 37-18 22"
              stroke="#e7f1e7"
              strokeWidth="25"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
            <path
              d="m117 323 19 9-2 17c-9 4-24-1-35-5-6-3-4-9 1-12Z"
              fill="#07271e"
              stroke="#81b69b"
              strokeWidth="2"
            />
            <path d="m117 335-4 10" stroke="#81b69b" strokeWidth="2" />
          </g>
          <g className="bull-leg-right">
            <path
              d="m206 275 10 35 21 21"
              stroke="#e7f1e7"
              strokeWidth="25"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
            <path
              d="m226 331 19-10 17 10c6 4 7 10 1 13-12 5-27 8-35 5Z"
              fill="#07271e"
              stroke="#81b69b"
              strokeWidth="2"
            />
            <path d="m245 334 5 11" stroke="#81b69b" strokeWidth="2" />
          </g>
          <g className="bull-arm-left">
            <path
              d="m133 218-29 28-29-24"
              stroke="#e7f1e7"
              strokeWidth="21"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
            <path
              d="M65 231c-10-5-17-13-15-21l4-13c1-5 8-6 10-1l3 7 8-5c7-4 12 1 10 8l-3 18Z"
              fill="#07271e"
              stroke="#81b69b"
              strokeWidth="2"
            />
            <path d="m61 206 12 12" stroke="#81b69b" strokeWidth="2" />
          </g>
          <g className="bull-arm-right">
            <path
              d="m228 215 29-18 13-29"
              stroke="#e7f1e7"
              strokeWidth="21"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
            <path
              d="M260 173c-5-6-4-14 0-18l5-9c3-5 9-3 10 2l1 6 8-4c7-3 12 2 8 9l-15 21Z"
              fill="#07271e"
              stroke="#81b69b"
              strokeWidth="2"
            />
            <path d="m273 160 7 9" stroke="#81b69b" strokeWidth="2" />
          </g>
          <path
            d="M143 193c-15 2-25 14-29 29l20 10-6 44c27 17 71 18 103 0l-6-44 20-10c-4-15-15-27-30-29Z"
            fill="#64cba0"
            stroke="#092c22"
            strokeWidth="3"
            strokeLinejoin="round"
          />
          <path
            d="M144 196c11 22 58 23 71-1"
            stroke="#369a72"
            strokeWidth="7"
          />
          <path
            d="M157 244h45l6 21c-17 8-35 8-51 0Z"
            fill="#46b98b"
            stroke="#369a72"
            strokeWidth="2"
          />
          <path d="m144 224-4 46m75-46 4 46" stroke="#369a72" strokeWidth="2" />
          <g className="bull-head">
            <path
              d="M153 107c-35-5-57-32-46-68 3 29 23 42 51 47Z"
              fill="#f4f4df"
            />
            <path
              d="M207 107c35-5 57-32 46-68-3 29-23 42-51 47Z"
              fill="#f4f4df"
            />
            <path
              d="M145 117c-15-16-33-13-39-5 8 18 24 21 39 18Z"
              fill="#e7f1e7"
            />
            <path
              d="M215 117c15-16 33-13 39-5-8 18-24 21-39 18Z"
              fill="#e7f1e7"
            />
            <path
              d="m114 115 24 8m108-8-24 8"
              stroke="#86a995"
              strokeWidth="4"
              strokeLinecap="round"
            />
            <path
              d="M142 103c20-17 56-17 76 0 12 10 9 35 1 52l-11 34c-9 21-47 21-56 0l-11-34c-8-17-11-42 1-52Z"
              fill="#e7f1e7"
              stroke="#092c22"
              strokeWidth="3"
            />
            <path
              d="M168 96c6-4 17-4 24 0l-4 36 6 39h-28l6-39Z"
              fill="#163e2f"
            />
            <path d="m160 106 13-14 4 15 12-14 11 14" fill="#f4f4df" />
            <g className="bull-blink" fill="#12382b">
              <ellipse cx="153" cy="137" rx="4" ry="6" />
              <ellipse cx="207" cy="137" rx="4" ry="6" />
            </g>
            <path
              d="m146 124 12-3m44 0 12 3"
              stroke="#12382b"
              strokeWidth="3"
              strokeLinecap="round"
            />
            <path
              d="M153 165c14-11 40-11 54 0 7 6 6 22-1 28-12 11-40 11-52 0-7-6-8-22-1-28Z"
              fill="#c7ddc8"
            />
            <ellipse
              cx="164"
              cy="174"
              rx="3"
              ry="4"
              fill="#527763"
              transform="rotate(-25 164 174)"
            />
            <ellipse
              cx="196"
              cy="174"
              rx="3"
              ry="4"
              fill="#527763"
              transform="rotate(25 196 174)"
            />
            <path
              d="M169 186q11 10 22-1"
              stroke="#163e2f"
              strokeWidth="3"
              strokeLinecap="round"
            />
          </g>
        </g>
      </svg>
    </div>
  );
}
