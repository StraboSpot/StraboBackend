<?php
/**
 * StraboExperimental - New Interface
 *
 * This is the main entry point for the new StraboExperimental interface.
 * It wraps the Vue.js SPA with the site header and footer.
 */

// Session check - require login
require_once("../includes/sessioncheck.php");

// Get user info from session
$userpkey = $_SESSION['userpkey'];
$username = $_SESSION['username'];

// Include site header
include("../includes/mheader.php");
?>

<!-- Vue App Container -->
<div id="main" class="wrapper style1">
    <div class="container">
        <div id="vue-app">
            <!-- Vue app mounts here -->
            <div style="text-align: center; padding: 50px;">
                <p>Loading StraboExperimental...</p>
            </div>
        </div>
    </div>
</div>

<!-- Pass PHP session data to JavaScript -->
<script>
    window.STRABO_CONFIG = {
        userPkey: <?php echo json_encode($userpkey); ?>,
        username: <?php echo json_encode($username); ?>,
        basePath: '/newexperimental',
        apiPath: '/expdb'
    };
</script>

<!-- Load Vue App (built assets) -->
<script type="module" src="/newexperimental/dist/assets/main.js"></script>
<link rel="stylesheet" href="/newexperimental/dist/assets/main.css">

<?php
// Include site footer
include("../includes/mfooter.php");
?>
