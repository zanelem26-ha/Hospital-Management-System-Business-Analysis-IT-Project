        </main><!-- /.main-content -->

        <footer class="page-footer">
            &copy; <?= date('Y') ?> <?= APP_NAME ?> &mdash; <?= APP_VERSION ?>
        </footer>

    </div><!-- /.main-wrapper -->

</div><!-- /.layout -->

<!-- Session expiry warning -->
<div id="session-timeout-modal" class="modal-overlay" hidden aria-modal="true" role="dialog"
     aria-labelledby="session-timeout-title">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="session-timeout-title">&#8987; Session Expiring</h3>
        </div>
        <div class="modal-body">
            <p id="session-timeout-msg"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="dismissSessionTimeout()">Ignore</button>
            <button type="button" class="btn btn-primary" onclick="staySessionOnline()">Stay Online</button>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/js/main.js"></script>
<script src="<?= BASE_URL ?>/js/sync_manager.js"></script>
<script src="<?= BASE_URL ?>/js/session_timeout.js"></script>
</body>
</html>
