<header class="qms-topbar">
    <div class="qms-topbar__brand">
        <span class="qms-topbar__dot" aria-hidden="true"></span>
        <span class="qms-topbar__name">Smart QMS</span>
        <span class="qms-topbar__sub">staff panel</span>
    </div>

    <div class="qms-topbar__actions">
        <label class="qms-desk">
            <span class="qms-desk__label">My desk</span>
            <select id="qms-counter-select" class="qms-desk__select" aria-label="Which counter am I?">
                <?php foreach ($counters as $counter): ?>
                    <option value="<?= (int) $counter['id'] ?>">
                        <?= e($counter['name']) ?><?= $counter['staff_name'] !== null ? ' — ' . e($counter['staff_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <form method="post" action="<?= e(url('/staff/logout')) ?>" class="qms-topbar__logout">
            <input type="hidden" name="_token" value="<?= e($csrf) ?>">
            <button type="submit" class="qms-btn qms-btn--ghost">Sign out</button>
        </form>
    </div>
</header>

<p id="qms-status" class="qms-status" role="status" aria-live="polite">Connecting…</p>

<main class="qms-main">
    <section class="qms-counters" id="qms-counters" aria-label="Counters">
        <p class="qms-empty">Loading counters…</p>
    </section>

    <aside class="qms-side" aria-label="Queue overview">
        <h2 class="qms-side__title">Waiting by service</h2>
        <div id="qms-services" class="qms-services">
            <p class="qms-empty">…</p>
        </div>

        <h2 class="qms-side__title">Today</h2>
        <dl id="qms-totals" class="qms-totals"></dl>

        <p class="qms-side__note">
            Refreshes every <span id="qms-poll-seconds">3</span> seconds.
        </p>
    </aside>
</main>

<div id="qms-toast" class="qms-toast" role="alert" aria-live="assertive" hidden></div>

<script type="application/json" id="qms-config"><?= json_script([
    'csrf'    => $csrf,
    'pollMs'  => 3000,
    'login'   => url('/staff/login'),
    'api'     => url('/api/staff'),
]) ?></script>
<script src="<?= e(asset('staff.js')) ?>"></script>
