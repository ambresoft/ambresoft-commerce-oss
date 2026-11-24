import '@testing-library/jest-dom/vitest';
import { router } from '@inertiajs/react';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

// Component tests mount no Inertia page, so client-side visits only update the URL.
router.replace = (params) => window.history.replaceState(null, '', params.url);

// jsdom does not implement native dialog behavior; production uses the browser's
// focus trap, Escape handling and focus restoration, not this test-only shim.
Object.defineProperties(HTMLDialogElement.prototype, {
  showModal: {
    configurable: true,
    value: function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    },
  },
  close: {
    configurable: true,
    value: function (this: HTMLDialogElement) {
      this.removeAttribute('open');
    },
  },
});

// jsdom has no EventSource. Stream behavior is exercised with controllable events in Notifications.test.tsx.
class TestEventSource extends EventTarget {
  onerror: (() => void) | null = null;
  close() {}
}
Object.defineProperty(globalThis, 'EventSource', {
  configurable: true,
  writable: true,
  value: TestEventSource,
});
