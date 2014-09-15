FreePBXRouteGraph
=================

Shows a graph from the FreePBX Database of the call structure

This is a quick php script/hack to privde a graph of the call structure directly out of the freepbx database. I have done better work in the past, I promise :)

To install..

pushd /var/www/html/
git clone https://github.com/mattsoftware.com/FreePBXRouteGraph/ graph
popd
vim /etc/freepbx_graph_config.php

The contents of the freepbx_graph_config.php only needs to contain the password of the freepbxuser in the database. Unless 
your installation is more advanced than the deafult installation this should be fine in most cases. For example...

<?php
$database_password = "supersecretsquirrel";

