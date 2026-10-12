<div class="qms-board">
    <header class="qms-board__head">
        <div class="qms-board__brand">
            <span class="qms-board__dot" aria-hidden="true"></span>
            <span>Smart QMS</span>
        </div>
        <div class="qms-board__clock" id="qms-clock" aria-hidden="true">--:--:--</div>
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
]) ?></script>
<script src="<?= e(asset('display.js')) ?>"></script>
