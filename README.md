FreePBXRouteGraph
=================

Shows a graph from the FreePBX Database of the call structure

This is a quick php script/hack to privde a graph of the call structure directly out of the freepbx database. I have done better work in the past, I promise :)

To install..

```bash
pushd /var/www/html/
git clone https://github.com/mattsoftware/FreePBXRouteGraph.git graph
popd
vim /etc/freepbx_graph_config.php
```

The contents of the freepbx_graph_config.php only needs to contain the password of the freepbxuser in the database. Unless 
your installation is more advanced than the deafult installation this should be fine in most cases. For example...

```php
<?php
$database_password = "supersecretsquirrel";
```

This password may be found by running the following command on the FreePBX box...

```bash
cat /etc/amportal.conf | grep AMPDBPASS
```

Development Notes
=================

Adding new items
----------------

* Add the buildNodesFromQuery
  * First agument is the sql query to get the list of items to add
  * Second argument is the name to give the nodes we are importing
  * Third argument is the column to get the destination from

* Create a new function which will take the data from the mysql query (single row) and turn it into a graphviz node

* In the createNode function, add a case with the new name
  * This should call the new function to return the specific graphviz node

* In the getNodeName function, add a case with the new name to build the freepbx well-known name for the node

