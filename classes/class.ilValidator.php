<?php
/*
	+-----------------------------------------------------------------------------+
	| ILIAS open source                                                           |
	+-----------------------------------------------------------------------------+
	| Copyright (c) 1998-2001 ILIAS open source, University of Cologne            |
	|                                                                             |
	| This program is free software; you can redistribute it and/or               |
	| modify it under the terms of the GNU General Public License                 |
	| as published by the Free Software Foundation; either version 2              |
	| of the License, or (at your option) any later version.                      |
	|                                                                             |
	| This program is distributed in the hope that it will be useful,             |
	| but WITHOUT ANY WARRANTY; without even the implied warranty of              |
	| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the               |
	| GNU General Public License for more details.                                |
	|                                                                             |
	| You should have received a copy of the GNU General Public License           |
	| along with this program; if not, write to the Free Software                 |
	| Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA. |
	+-----------------------------------------------------------------------------+
*/

/**
* ILIAS Data Validator & Recovery Tool
*
* @author	Sascha Hofmann <shofmann@databay.de> 
* @version	$Id$
*
* @package	ilias-tools
*/
class ilValidator extends PEAR
{
	/**
	* name of RecoveryFolder
	* @var	string
	*/
	var $rfolder_name = "__Restored Objects";

	/**
	* ref_id of RecoveryFolder
	* @var	integer
	*/
	var $rfolder_ref_id = NULL;

	/**
	* all valid rbac object types
	* @var	string
	*/
	var $rbac_object_types = NULL;

	/**
	* list of object types to exclude from recovering
	* @var	array
	*/
	var $object_types_exclude = array("adm","root","ldap","mail","usrf","objf","lngf");
	
	/**
	* set mode
	* @var	array
	*/
	var $mode = array(
						"analyze"		=> true,
						"clean" 		=> false,
						"restore"		=> false,
						"empty_trash"	=> false
					);

	/**
	* unbound references
	* @var	array
	*/
	var $unbound_references = array();

	/**
	* unbound childs (tree entries)
	* @var	array
	*/
	var $unbound_childs = array();

	/**
	* missing objects
	* @var	array
	*/
	var $missing_objects = array();

	/**
	* childs with invalid parent
	* @var	array
	*/
	var $childs_with_invalid_parent = array();

	/**
	* Constructor
	* 
	* @access	public
	* @param	integer	mode
	*/
	function ilValidator()
	{
		global $objDefinition, $ilDB;
		
		$this->PEAR();
		$this->db =& $ilDB;
		$this->rbac_object_types = "'".implode("','",$objDefinition->getAllRBACObjects())."'";
        $this->setErrorHandling(PEAR_ERROR_CALLBACK,array(&$this, 'handleErr'));
	}

	/**
	* set mode of ilValidator
	* Usage: setMode("restore",true)	=> enable object restorey
	* 		 setMode("all",true) 		=> enable all features
	* 		 For all possible modes see variables declaration
	*
	* @access	public
	* @param	string	mode
	* @param	boolean	value (true=enable/false=disable)
	* @return	boolean	false on error
	*/
	function setMode($a_mode,$a_value)
	{
		if ((!in_array($a_mode,array_keys($this->mode)) and $a_mode != "all") or !is_bool($a_value))
		{
			$this->throwError(INVALID_PARAM, FATAL, DEBUG);
			return false;
		}
		
		if ($a_mode == "all")
		{
			foreach ($this->mode as $mode => $value)
			{
				$this->mode[$mode] = $a_value;
			}
		}
		else
		{
			$this->mode[$a_mode] = $a_value;
		}
		
		return true;
	}
	
	/**
	* Is a particular mode enabled?
	*
	* @access	public
	* @param	string	mode to query
	* @return	boolean
	*/
	function isModeEnabled($a_mode)
	{
		if (!in_array($a_mode,array_keys($this->mode)))
		{
			$this->throwError(VALIDATER_UNKNOWN_MODE, WARNING, DEBUG);
			return false;
		}
		
		return $this->mode[$a_mode];
	}

	/**
	* Search database for all object entries with missing reference and/or tree entry
	* and stores result in $this->missing_objects
	* 
	* @access	public
	* @return	boolean	false if analyze mode disabled or nothing found
	* @see		this::getMissingObjects()
	* @see		this::restoreMissingObjects()
	*/
	function findMissingObjects()
	{
		// check mode: analyze
		if ($this->mode["analyze"] !== true)
		{
			return false;
		}

		$q = "SELECT object_data.*, ref_id FROM object_data ".
			 "LEFT JOIN object_reference ON object_data.obj_id = object_reference.obj_id ".
			 "LEFT JOIN tree ON object_reference.ref_id = tree.child ".
			 "WHERE (object_reference.obj_id IS NULL OR tree.child IS NULL) ".
			 "AND object_data.type IN (".$this->rbac_object_types.")";
		$r = $this->db->query($q);
		
		while ($row = $r->fetchRow(DB_FETCHMODE_OBJECT))
		{
			$this->missing_objects[] = array(
											"obj_id"	=> $row->obj_id,
											"type"		=> $row->type,
											"ref_id"	=> $row->ref_id,
											"child"		=> $row->child
											);
		}

		return (count($this->missing_objects) > 0) ? true : false;
	}

	/**
	* Gets all object entries with missing reference and/or tree entry.
	* Returns array with
	*		obj_id		=> actual object entry with missing reference or tree
	*		type		=> symbolic name of object type
	*		ref_id		=> reference entry of object (or NULL if missing)
	* 		child		=> always NULL (only for debugging and verification)
	* 
	* @access	public
	* @return	array
	* @see		this::findMissingObjects()
	* @see		this::restoreMissingObjects()
	*/
	function getMissingObjects()
	{
		return $this->missing_objects;
	}

	/**
	* Search database for all reference entries that are not linked with a valid object id
	* and stores result in $this->unbound_references
	* 
	* @access	public
	* @return	boolean	false if analyze mode disabled or nothing found
	* @see		this::getUnboundReferences()
	* @see		this::removeUnboundReferences()
 	*/	
	function findUnboundReferences()
	{
		// check mode: analyze
		if ($this->mode["analyze"] !== true)
		{
			return false;
		}

		$q = "SELECT object_reference.* FROM object_reference ".
			 "LEFT JOIN object_data ON object_data.obj_id = object_reference.obj_id ".
			 "WHERE object_data.obj_id IS NULL ".
			 "OR object_data.type NOT IN (".$this->rbac_object_types.")";
		$r = $this->db->query($q);
		
		while ($row = $r->fetchRow(DB_FETCHMODE_OBJECT))
		{
			$this->unbound_references = array(
											"ref_id"	=> $row->ref_id,
											"obj_id"	=> $row->obj_id
											);
		}

		return (count($this->unbound_references) > 0) ? true : false;
	}

	/**
	* Gets all reference entries that are not linked with a valid object id.
	* 
	* @access	public
	* @return	array
	* @see		this::findUnboundReferences()
	* @see		this::removeUnboundedReferences()
	*/	
	function getUnboundReferences()
	{
		return $this->unbound_references;
	}

	/**
	* Search database for all tree entries without any link to a valid object
	* and stores result in $this->unbound_childs
	* 
	* @access	public
	* @return	boolean	false if analyze mode disabled or nothing found
	* @see		this::getUnboundChilds()
	* @see		this::removeUnboundChilds()
	*/
	function findUnboundChilds()
	{
		// check mode: analyze
		if ($this->mode["analyze"] !== true)
		{
			return false;
		}

		$q = "SELECT tree.*,object_reference.ref_id FROM tree ".
			 "LEFT JOIN object_reference ON tree.child = object_reference.ref_id ".
			 "WHERE object_reference.ref_id IS NULL";
		$r = $this->db->query($q);
		
		while ($row = $r->fetchRow(DB_FETCHMODE_OBJECT))
		{
			$this->unbound_childs[] = array(
											"child"		=> $row->child,
											"ref_id"	=> $row->ref_id
											);
		}

		return (count($this->unbound_childs) > 0) ? true : false;
	}

	/**
	* Gets all tree entries without any link to a valid object
	* 
	* @access	public
	* @return	array
	* @see		this::findUnboundChilds()
	* @see		this::removeUnboundChilds()
	*/
	function getUnboundChilds()
	{
		return $this->unbound_childs;
	}

	/**
	* Search database for all tree entries having no valid parent (=> no valid path to root node)
	* and stores result in $this->childs-with_invalid_parent
	*
	* @access	public
	* @return	boolean	false if analyze mode disabled or nothing found
	* @see		this::getChildsWithInvalidParent()
	* @see		this::restoreUnboundedChilds()
	*/
	function findChildsWithInvalidParent()
	{
		// check mode: analyze
		if ($this->mode["analyze"] !== true)
		{
			return false;
		}

		$q = "SELECT T2.tree AS deleted,T1.child,T1.parent,T2.parent AS grandparent FROM tree AS T1 ".
			 "LEFT JOIN tree AS T2 ON T2.child=T1.parent ".
			 "WHERE (T2.tree!=1 OR T2.tree IS NULL) AND T1.parent!=0";
		$r = $this->db->query($q);
		
		while ($row = $r->fetchRow(DB_FETCHMODE_OBJECT))
		{
			$this->childs_with_invalid_parent = array(
													"child"			=> $row->child,
													"parent"		=> $row->parent,
													"grandparent"	=> $row->grandparent,
													"deleted"		=> $row->deleted
													);
		}

		return (count($this->childs_with_invalid_parent) > 0) ? true : false;
	}

	/**
	* Gets all tree entries having no valid parent (=> no valid path to root node)
	* Returns an array with
	*		child		=> actual entry with broken uplink to its parent
	*		parent		=> parent of child that does not exist
	*		grandparent	=> grandparent of child (where path to root node continues)
	* 		deleted		=> containing a negative number (= parent in trash) or NULL (parent does not exists at all)
	* 
	* @access	public
	* @return	array
	* @see		this::findChildsWithInvalidParent()
	* @see		this::restoreUnboundedChilds()
	*/
	function getChildsWithInvalidParent()
	{
		return $this->childs_with_invalid_parent;
	}

	/**
	* Removes all reference entries that are linked with invalid object IDs
	* 
	* @access	public
	* @param	array	invalid IDs in object_reference (optional)
	* @return	boolean	true if any ID were removed / false on error or clean mode disabled
	* @see		this::getUnboundReferences()
	* @see		this::findUnboundReferences()
	*/
	function removeUnboundReferences($a_unbound_refs = NULL)
	{
		// check mode: clean
		if ($this->mode["clean"] !== true)
		{
			return false;
		}

		if ($a_unbound_refs === NULL and isset($this->unbound_references))
		{
			$a_unbound_refs =& $this->unbound_references; 
		}

		// handle wrong input
		if (!is_array($a_unbound_refs))
		{
			$this->throwError(INVALID_PARAM, WARNING, DEBUG);
			return false;
		}
		// no unbound references found. do nothing
		if (count($a_unbound_refs) == 0)
		{
			return false;
		}

/*******************
restore starts here
********************/

		foreach ($a_unbound_refs as $entry)
		{
			$q = "DELETE FROM object_reference WHERE ref_id='".$entry["ref_id"]."' AND obj_id='".$entry["obj_id"]."'";
			$this->db->query($q);
		}
		
		return true;	
	}

	/**
	* Removes all tree entries without any link to a valid object
	* 
	* @access	public
	* @param	array	invalid IDs in tree (optional)
	* @return	boolean	true if any ID were removed / false on error or clean mode disabled
	* @see		this::getUnboundChilds()
	* @see		this::findUnboundChilds()
	*/
	function removeUnboundChilds($a_unbound_childs = NULL)
	{
		// check mode: clean
		if ($this->mode["clean"] !== true)
		{
			return false;
		}

		if ($a_unbound_childs === NULL and isset($this->unbound_childs))
		{
			$a_unbound_childs =& $this->unbound_childs; 
		}

		// handle wrong input
		if (!is_array($a_unbound_childs))
		{
			$this->throwError(INVALID_PARAM, WARNING, DEBUG);
			return false;
		}

		// no unbound childs found. do nothing
		if (count($a_unbound_childs) == 0)
		{
			return false;
		}

/*******************
remove starts here
********************/

		foreach ($a_unbound_childs as $entry)
		{
			$q = "DELETE FROM tree WHERE child='".$entry["child"]."'";
			$this->db->query($q);
		}
		
		return true;	
	}

	/**
	* Restores missing reference and/or tree entry for all objects found by this::getMissingObjects()
	* Restored object are placed in RecoveryFolder defined in this::rfolder_name
	* 
	* @access	public
	* @param	integer	ref_id of RecoveryFolder (optional)
	* @param	array	obj_ids of missing objects (optional)
	* @return	boolean	true if any object were restored / false on error or restore mode disabled
	* @see		this::getMissingObjects()
	* @see		this::findMissingObjects()
	*/
	function restoreMissingObjects($a_rfolder_ref_id = NULL,$a_missing_objects = NULL)
	{
		global $tree;

		// check mode: restore
		if ($this->mode["restore"] !== true)
		{
			return false;
		}

		if ($a_rfolder_ref_id === NULL)
		{
			if ($this->rfolder_ref_id !== NULL)
			{
				$a_rfolder_ref_id = $this->rfolder_ref_id;
			}
			else
			{
				$a_rfolder_ref_id = $this->getRecoveryFolderId();
			}
		}

		if ($a_missing_objects === NULL and isset($this->missing_objects))
		{
			$a_missing_objects = $this->missing_objects;
		}

		// handle wrong input
		if (!is_integer($a_rfolder_ref_id) or !is_array($a_missing_objects)) 
		{
			$this->throwError(INVALID_PARAM, WARNING, DEBUG);
			return false;
		}

		// no missing objectss found. do nothing
		if (count($a_missing_objects) == 0)
		{
			return false;
		}
		
/*******************
restore starts here
********************/

		$restored = false;
		
		foreach ($a_missing_objects as $missing_obj)
		{
			// restore ref_id in case of missing
			if ($missing_obj["ref_id"] == NULL)
			{
				$missing_obj["ref_id"] = $this->restoreReference($missing_obj["obj_id"]);
			}
			
			// put in tree under RecoveryFolder if not on exclude list
			if (!in_array($missing_obj["type"],$this->object_types_exclude))
			{
				$tree->insertNode($missing_obj["ref_id"],$a_rfolder_ref_id);
				$restored = true;
			}
			
			// TODO: process rolefolders
		}
		
		return $restored;
	}

	/**
	* restore a reference for an object
	* Creates a new reference entry in DB table object_reference for $a_obj_id
	* 
	* @param	integer	obj_id
	* @access	private
	* @return	integer/boolean	generated ref_id or false on error
	* @see		this::restoreMissingObjects()	
	*/
	function restoreReference($a_obj_id)
	{
		if (empty($a_obj_id))
		{
			$this->throwError(INVALID_PARAM, WARNING, DEBUG);
			return false;
		}
		
		$q = "INSERT INTO object_reference (ref_id,obj_id) VALUES ('0','".$a_obj_id."')";
		$this->db->query($q);
		
		return getLastInsertId();
	}

	/**
	* Restore objects (and their subobjects) that are valid but not linked correctly
	* to the hierarchy because they point to an invalid parent_id
	*
	* @access	public
 	* @param	integer	ref_id of RecoveryFolder (optional)
	* @param	array	list of childs with invalid parents (optional)
	* @return	boolean false on error or restore mode disabled
	* @see		this::getChildsWithInvalidParent()
	* @see		this::findChildsWithInvalidParent()
	*/
	function restoreUnboundChilds($a_rfolder_ref_id = NULL,$a_childs_with_invalid_parent = NULL)
	{
		global $tree,$rbacadmin,$ilias;

		// check mode: restore
		if ($this->mode["restore"] !== true)
		{
			return false;
		}
		
		if ($a_rfolder_ref_id === NULL)
		{
			if ($this->rfolder_ref_id !== NULL)
			{
				$a_rfolder_ref_id = $this->rfolder_ref_id;
			}
			else
			{
				$a_rfolder_ref_id = $this->getRecoveryFolderId();
			}
		}
		
		if ($a_childs_with_invalid_parent === NULL and isset($this->childs_with_invalid_parent))
		{
			$a_childs_with_invalid_parent = $this->childs_with_invalid_parent;
		}

		// handle wrong input
		if (!is_integer($a_rfolder_ref_id) or !is_array($a_childs_with_invalid_parent)) 
		{
			$this->throwError(INVALID_PARAM, WARNING, DEBUG);
			return false;
		}

		// no invalid parents found. do nothing
		if (count($a_childs_with_invalid_parent) == 0)
		{
			return false;
		}
		
/*******************
restore starts here
********************/
		
		// process move subtree
		foreach ($a_childs_with_invalid_parent as $entry)
		{
			// get node data
			$top_node = $tree->getNodeData($entry["child"]);
			
			// don't save rolefolders, remove them
			// TODO process ROLE_FOLDER_ID
			if ($top_node["type"] == "rolf")
			{
				$rolfObj = $ilias->obj_factory->getInstanceByRefId($top_node["child"]);
				$rolfObj->delete();
				unset($top_node);
				unset($rolfObj);
				continue;
			}

			// get subnodes of top nodes
			$subnodes[$entry["child"]] = $tree->getSubtree($top_node);
		
			// delete old tree entries
			$tree->deleteTree($top_node);
		}

		// now move all subtrees to new location
		// TODO: this whole put in place again stuff needs revision. Permission settings get lost.
		foreach ($subnodes as $key => $subnode)
		{
			// first paste top_node ...
			$rbacadmin->revokePermission($key);
			$obj_data =& $ilias->obj_factory->getInstanceByRefId($key);
			$obj_data->putInTree($a_objRecover->getRefId());
			$obj_data->setPermissions($a_objRecover->getRefId());

			// ... remove top_node from list ...
			array_shift($subnode);
			
			// ... insert subtree of top_node if any subnodes exist
			if (count($subnode) > 0)
			{
				foreach ($subnode as $node)
				{
					$rbacadmin->revokePermission($node["child"]);
					$obj_data =& $ilias->obj_factory->getInstanceByRefId($node["child"]);
					$obj_data->putInTree($node["parent"]);
					$obj_data->setPermissions($node["parent"]);
				}
			}
		}
		
		return true;
	}

	/**
	* Gets reference id of RecoveryFolder. Looks for an object named this::rfolder_name
	* and returns its ref_id. If RecoveryFolder is not found it will be created by calling private method this::creatRecoveryFolder().
	* 
	* @access	public
	* @return	integer	ref_id of RecoveryFolder
	* @see		this::restoreUnboundedChilds()
	* @see		this::restoreMissingObjects()
	*/
	function getRecoveryFolderId()
	{
		global $ilias;
		
		if (is_integer($this->rfolder_ref_id))
		{
			return $this->rfolder_ref_id;
		}
		
		// RecoveryFolder not set. Fetch from database
		$q = "SELECT ref_id FROM object_reference ".
			 "LEFT JOIN object_data ON object_data.obj_id=object_reference.obj_id ".
			 "WHERE object_data.title = '".$this->rfolder_name."'";
		$r = $this->db->getRow($q);

		// RecoveryFolder does not exists. Create one
		if (!$r->ref_id)
		{
			return $this->createRecoveryFolder();
		}
		else
		{
			$this->rfolder_ref_id = (int) $r->ref_id;

			return $this->rfolder_ref_id;
		}
	}

	/**
	* create the RecoveryFolder. RecoveryFolder is a category object and 
	* will be created under ILIAS root node by default.
	*
	* @access	private
	* @param	integer	ref_id of parent object where RecoveryFolder should be created (optional)
	* @return	integer	ref_id of RecoveryFolder
	* @see		this::getRecoveryFolderId()
 	*/
	function createRecoveryFolder($a_parent_id = ROOT_FOLDER_ID)
	{
		include_once "classes/class.ilObjCategory.php";
		$objRecover = new ilObjCategory();
		$objRecover->setTitle(ilUtil::stripSlashes($this->rfolder_name));
		$objRecover->setDescription(ilUtil::stripSlashes("Contains objects restored by recovery tool"));
		$objRecover->create();
		$objRecover->createReference();
		$objRecover->putInTree($a_parent_id);
		$objRecover->addTranslation($objRecover->getTitle(),$objRecover->getDescription(),"en",1);
		// don't set any permissions -> RecoveryFolder is only accessible by admins

		$this->rfolder_ref_id = (int) $objRecover->getRefId();
		unset($objRecover);

		return $this->rfolder_ref_id;
	}

	/**
	* close gaps in lft/rgt values of a tree
	* Wrapper for ilTree::renumber()
	* 
	* @access	public
	* @return	boolean false if clean mode disabled
	* @see		ilTree::renumber()
	*/
	function closeGapsInTree()
	{
		global $tree;
		
		// check mode: clean
		if ($this->mode["clean"] !== true)
		{
			return false;
		}

		$tree->renumber(ROOT_FOLDER_ID);

		return true;
	}

	/**
	* Callback function
	* handles PEAR_error and outputs detailed infos about error
	* TODO: implement that in global errorhandler of ILIAS (via templates)
	* 
	* @access	private
	* @param	object	PEAR_error
	*/
	function handleErr($error)
	{
		$call_loc = $error->backtrace[count($error->backtrace)-1];
		$num_args = count($call_loc["args"]);

		if ($num_args > 0)
		{
			foreach ($call_loc["args"] as $arg)
			{
				$type = gettype($arg);
				
				switch ($type)
				{
					case "string":
						$value = strlen($arg);
						break;

					case "array":
						$value = count($arg);
						break;

					case "object":
						$value = get_class($arg);
						break;

					case "boolean":
						$value = ($arg) ? "true" : "false";
						break;
						
					default:
						$value = $arg;
						break;
				}
				
				$arg_list[] = array(
									"type"	=> $type,
									"value"	=> "(".$value.")"
									);
			}
			
			foreach ($arg_list as $arg)
			{
				$arg_str .= implode("",$arg)." ";
			}
		}

		$err_msg = "<br/><b>".$error->getCode().":</b> ".$error->getMessage()." in ".$call_loc["class"].$call_loc["type"].$call_loc["function"]."()".
				   "<br/>Called from: ".basename($call_loc["file"])." , line ".$call_loc["line"].
				   "<br/>Passed parameters: [".$num_args."] ".$arg_str."<br/>";
		printf($err_msg);
		
		if ($error->getUserInfo())
		{
			printf("<br/>Parameter details:");
			echo "<pre>";
			var_dump($call_loc["args"]);
			echo "</pre>";
		}
		
		if ($error->getCode() == FATAL)
		{
			exit();
		}
	}
} // END class.ilValidator
?>