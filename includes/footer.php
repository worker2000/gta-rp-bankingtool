</main>

<?php if (Auth::check()): ?>
<footer class="footer mt-auto py-3 bg-dark border-top border-secondary">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-muted"><?= APP_NAME ?> v<?= APP_VERSION ?></span>
            <span class="text-muted"><?= date('d.m.Y H:i') ?></span>
        </div>
    </div>
</footer>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
