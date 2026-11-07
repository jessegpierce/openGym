<?php
// Copy this file to config.php in the same folder and fill in both values.
// config.php is blocked from the web by .htaccess.
return [
    // The address SiteGround gave the openGym Node.js project (Node.js Projects > the project).
    // No trailing slash. Example: 'https://opengym-abc123.sg-host.com'
    'node_url' => 'https://REPLACE-WITH-YOUR-NODE-PROJECT-ADDRESS',

    // The same random string as PROXY_SECRET in the Node.js project's environment variables.
    'secret'   => 'REPLACE-WITH-THE-SAME-SECRET',
];
