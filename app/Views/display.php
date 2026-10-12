<div class="qms-board">
    <header class="qms-board__head">
        <div class="qms-board__brand">
            <span class="qms-board__dot" aria-hidden="true"></span>
            <span>Smart QMS</span>
        </div>
        <div class="qms-board__head-right">
            <button type="button" class="qms-sound" id="qms-sound" aria-pressed="false">
                <span class="qms-sound__icon" aria-hidden="true">🔔</span>
                <span class="qms-sound__label">Click to enable sound</span>
            </button>
            <div class="qms-board__clock" id="qms-clock" aria-hidden="true">--:--:--</div>
        </div>
    </header>

    <main class="qms-board__main">
        <section class="qms-board__counters" id="qms-counters" aria-label="Now serving">
            <p class="qms-board__empty">Connecting…</p>
        </section>

        <aside class="qms-board__side">
            <h2 class="qms-board__side-title">Waiting</h2>
            <div class="qms-board__services" id="qms-services">
                <p class="qms-board__empty">…</p>
            </div>
        </aside>
    </main>

    <footer class="qms-board__foot">
        <span id="qms-status">Connecting…</span>
    </footer>
</div>

<script type="application/json" id="qms-config"><?= json_script([
    'pollMs' => 3000,
    'api'    => $api,
    'speak'  => true,
]) ?></script>
<script src="<?= e(asset('display.js')) ?>"></script>
<script src="<?= e(asset('board-sound.js')) ?>"></script>
