<?php
// The home page of https://aduna.fit/ itself. Without it the server lists the domain's folders,
// which Google AdSense reviews as an empty site. .cpanel.yml puts it at $HOME/aduna.fit/index.php
// once, and never replaces an index.php that's already there.
header('Location: /AbroadPath/', true, 301);
exit;
