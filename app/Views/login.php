<main class="qms-login">
    <form class="qms-login__card" method="post" action="<?= e(url('/staff/login')) ?>" autocomplete="off">
        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <p class="qms-login__eyebrow">Smart QMS</p>
        <h1 class="qms-login__title">Staff sign in</h1>

        <?php if ($error !== null): ?>
            <p class="qms-login__error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <?php if (!$configured): ?>
            <p class="qms-login__error" role="alert">
                No staff password is configured. Add <code>STAFF_PASSWORD_HASH</code> to <code>.env</code> —
                login fails closed without it.
            </p>
        <?php endif; ?>

        <label class="qms-field">
            <span class="qms-field__label">Password</span>
            <input class="qms-field__input" type="password" name="password" required autofocus
                   autocomplete="current-password" <?= $configured ? '' : 'disabled' ?>>
        </label>

        <button class="qms-login__submit" type="submit" <?= $configured ? '' : 'disabled' ?>>Sign in</button>

        <p class="qms-login__hint">
            Generate the hash with
            <code>php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"</code>
        </p>
    </form>
</main>
