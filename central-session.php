<?php
// Application-owned compatibility shim: session selection belongs to includes/bootstrap.php.
// Safe when an older Apache/PHP prepend setting still points to this file.
require_once __DIR__ . '/includes/central-auth.php';
