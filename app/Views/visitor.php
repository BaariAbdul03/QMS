<main class="qms-visitor">
    <header class="qms-visitor__head">
        <p class="qms-visitor__eyebrow">Smart QMS</p>
        <h1 class="qms-visitor__title" id="qms-title">Take a token</h1>
        <p class="qms-visitor__hint" id="qms-hint">Choose a service to join the queue.</p>
    </header>

    <!-- The picker is server-rendered so the first paint already shows real
         queue lengths; the script keeps the counts fresh afterwards. -->
    <section class="qms-picker" id="qms-picker" aria-label="Services">
        <?php foreach ($services as $service): ?>
            <?php
            $shared    = (int) $service['shared_counters'];
            $dedicated = (int) $service['open_counters'] - $shared;
            $capacity  = $shared > 0 && $dedicated > 0
                ? sprintf('%d dedicated + %d shared', $dedicated, $shared)
                : sprintf('%d counter%s', (int) $service['open_counters'], (int) $service['open_counters'] === 1 ? '' : 's');
            ?>
            <button class="qms-pick" type="button"
                    data-service-id="<?= (int) $service['id'] ?>"
                    data-service-name="<?= e($service['name']) ?>">
                <span class="qms-pick__name"><?= e($service['name']) ?></span>
                <span class="qms-pick__prefix"><?= e($service['prefix']) ?> series</span>
                <span class="qms-pick__meta" data-role="meta">
                    <?= (int) $service['waiting'] ?> waiting &middot; <?= e($capacity) ?>
                </span>
            </button>
        <?php endforeach; ?>
    </section>

    <!-- The ticket view is built by visitor.js from the token in localStorage. -->
    <section class="qms-ticket" id="qms-ticket" aria-live="polite" hidden></section>

    <p class="qms-visitor__error" id="qms-error" role="alert" hidden></p>
</main>

<script type="application/json" id="qms-config"><?= json_script([
    'pollMs' => 3000,
    'api'    => $api,
]) ?></script>
<script src="<?= e(asset('visitor.js')) ?>"></script>
