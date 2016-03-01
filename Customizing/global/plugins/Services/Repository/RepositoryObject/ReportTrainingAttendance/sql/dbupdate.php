<#1>
<?php
$fields = 
	array(
		'id' => array(
			'type' => 'integer',
			'length' => 4,
			'notnull' => true
		),
		'is_online' => array(
			'type' => 'integer',
			'length' => 1,
			'notnull' => false
		),
	);
 
$ilDB->createTable("rep_robj_rta", $fields);
$ilDB->addPrimaryKey("rep_robj_rta", array("id"));
?>
