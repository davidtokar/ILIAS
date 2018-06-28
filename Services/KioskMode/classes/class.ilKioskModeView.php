<?php
/* Copyright (c) 2018 - Richard Klees <richard.klees@concepts-and-training.de> - Extended GPL, see LICENSE */

/**
 * Base class to be implemented and put in class-directory of module with the name
 * il$MODULEKioskModeView (e.g. ilTestKioskModeView).
 */
abstract class ilKioskModeView implements ILIAS\KioskModeView {
	/**
	 * @var	\ilCtrl
	 */
	protected $ctrl;

	/**
	 * @var \ilLanguage
	 */
	protected $lng;

	/**
	 * @var	\ilAccessHandler
	 */
	protected $access;

	/**
	 * @throws	\LogicException     if an object was provided that does not fit the module
	 *							    this view belongs to.	
	 * @throws	\RuntimeException   if user is not allowed to access the kiosk mode
	 *                              for the supplied object.
	 */
	final public function __construct(
		\ilObject $object,
		\ilCtrl $ctrl,
		\ilLanguage $lng,
		\ilAccessHandler $access
	) {
		$this->ctrl = $ctrl;
		$this->lng = $lng;
		$this->access = $access;
		if (!($object instanceof $this->getObjectClass())) {
			throw new \LogicException(
				"Provided object of class '".get_class($object)."' does not ".
				"fit view for '".$this->getObjectClass()."'"
			);
		}
		$this->setObject($object);
		if (!$this->hasPermissionToAccessKioskMode()) {
			throw new \RuntimeException(
				"User is not allowed to access the kiosk mode for the supplied object."
			);
		}
	}

	/**
	 * Get the class of objects this view displays.
	 */
	abstract protected function getObjectClass() : string;

	/**
	 * Set the object for this view.
	 *
	 * This makes it possible to use an appropriately typehinted member variable to
	 * allow for static code analysis. Sadly PHP has no generics...
	 */
	abstract protected setObject(\ilObject $object) : null;

	/**
	 * Check if the global user has permission to access the kiosk mode of the
	 * supplied object.
	 */
	abstract protected function hasPermissionToAccessKioskMode() : bool;

	// Note that methods of ILIAS\KioskMode\View need to be implemented as well.
}
