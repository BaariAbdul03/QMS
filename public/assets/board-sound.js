/* ==========================================================================
   Display board sound

   The board is a kiosk screen in a waiting room, so it has to announce a new
   token out loud. Two things make that harder than it looks:

   1. Browsers refuse to start audio until the user has interacted with the
      page. Nothing can be played on load, however much we want to — so the
      board shows an explicit "Click to enable sound" control and remembers the
      choice for next time.

   2. The chime is synthesised with the Web Audio API instead of an mp3. There
      is no binary asset to go missing, no 404 to silently mute the room, and it
      still works with no network.

   This file is deliberately separate from display.js and talks to it only
   through the `qms:called` event, so a board with no audio support (an old
   browser, a machine with no speakers) still renders perfectly.
   ========================================================================== */

(() => {
    'use strict';

    const button = document.getElementById('qms-sound');

    if (!button) {
        return;
    }

    const label = button.querySelector('.qms-sound__label');
    const icon = button.querySelector('.qms-sound__icon');

    const STORAGE_KEY = 'qms.board.sound';

    const configEl = document.getElementById('qms-config');
    const config = configEl ? JSON.parse(configEl.textContent) : {};
    const speakEnabled = config.speak !== false;

    /** The operator's preference. */
    let wanted = false;

    /** Whether the browser has actually granted us audio yet. */
    let unlocked = false;

    /** @type {AudioContext|null} */
    let context = null;

    try {
        wanted = window.localStorage.getItem(STORAGE_KEY) === 'on';
    } catch (error) {
        // Private mode, or storage disabled by policy. Sound is still usable,
        // it just will not be remembered.
        wanted = false;
    }

    // ------------------------------------------------------------- rendering --

    function paint() {
        const on = wanted && unlocked;

        button.classList.toggle('qms-sound--on', on);
        button.setAttribute('aria-pressed', on ? 'true' : 'false');

        if (!wanted || !unlocked) {
            icon.textContent = '🔔';
            label.textContent = 'Click to enable sound';
        } else {
            icon.textContent = '🔊';
            label.textContent = 'Sound on';
        }
    }

    // ----------------------------------------------------------------- audio --

    function unlock() {
        if (unlocked) {
            return true;
        }

        const Ctor = window.AudioContext || window.webkitAudioContext;

        if (!Ctor) {
            button.classList.add('qms-sound--unavailable');
            button.disabled = true;
            label.textContent = 'Sound unavailable';

            return false;
        }

        try {
            context = context || new Ctor();
        } catch (error) {
            button.classList.add('qms-sound--unavailable');
            button.disabled = true;
            label.textContent = 'Sound unavailable';

            return false;
        }

        if (context.state === 'suspended') {
            context.resume().catch(() => {});
        }

        unlocked = true;
        paint();

        return true;
    }

    /** A two-tone chime. Rising, short, and not annoying after the tenth time. */
    function ding() {
        if (!context || context.state !== 'running') {
            context && context.resume().catch(() => {});
        }

        const start = context.currentTime;

        [
            [880, 0],
            [1318.5, 0.16],
        ].forEach(([frequency, offset]) => {
            const oscillator = context.createOscillator();
            const gain = context.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;

            // Ramp in and out rather than switching on: a square edge clicks.
            gain.gain.setValueAtTime(0.0001, start + offset);
            gain.gain.exponentialRampToValueAtTime(0.2, start + offset + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.45);

            oscillator.connect(gain).connect(context.destination);
            oscillator.start(start + offset);
            oscillator.stop(start + offset + 0.5);
        });
    }

    /**
     * "A-014" -> "A zero one four".
     *
     * Reading the code as a number would turn A-014 into "A fourteen", which is
     * not how a queue number is called out in a waiting room.
     */
    function spellCode(code) {
        const [prefix, digits] = String(code).split('-');

        if (!digits) {
            return String(code);
        }

        const spoken = digits
            .split('')
            .map((digit) => (digit === '0' ? 'zero' : digit))
            .join(' ');

        return prefix + ' ' + spoken;
    }

    function speak(text) {
        if (!speakEnabled || !('speechSynthesis' in window)) {
            return;
        }

        try {
            const utterance = new SpeechSynthesisUtterance(text);

            utterance.rate = 0.95;
            utterance.pitch = 1;

            // A recall re-announces: drop anything still queued so the board is
            // never a sentence behind the queue.
            window.speechSynthesis.cancel();
            window.speechSynthesis.speak(utterance);
        } catch (error) {
            // Speech is a bonus. Never let it break the chime.
        }
    }

    // ---------------------------------------------------------------- wiring --

    button.addEventListener('click', () => {
        if (wanted && unlocked) {
            wanted = false;

            if (context) {
                context.suspend().catch(() => {});
            }
        } else {
            wanted = true;
            unlock();
        }

        try {
            window.localStorage.setItem(STORAGE_KEY, wanted ? 'on' : 'off');
        } catch (error) {
            // Not being able to remember the choice is not a failure.
        }

        // A first click is also the gesture that lets us answer with a chime, so
        // the operator can hear what they just enabled.
        if (wanted && unlocked && context) {
            ding();
        }

        paint();
    });

    document.addEventListener('qms:called', (event) => {
        if (!wanted || !unlock()) {
            return;
        }

        ding();

        const detail = event.detail || {};

        if (detail.code) {
            speak('Token ' + spellCode(detail.code) + ', ' + (detail.counter || 'counter'));
        }
    });

    // Sound was enabled on a previous visit, but the browser still needs one
    // interaction before it will play anything. The operator clicking anywhere
    // on the board is enough.
    if (wanted) {
        document.addEventListener('pointerdown', function once() {
            document.removeEventListener('pointerdown', once);
            unlock();
        });
    }

    paint();
})();
