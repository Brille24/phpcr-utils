<?php
declare(ENCODING = 'utf-8');

/*                                                                        *
 * This script belongs to the FLOW3 package "PHPCR".                      *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License as published by the *
 * Free Software Foundation, either version 3 of the License, or (at your *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser       *
 * General Public License for more details.                               *
 *                                                                        *
 * You should have received a copy of the GNU Lesser General Public       *
 * License along with the script.                                         *
 * If not, see http://www.gnu.org/licenses/lgpl.html                      *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * @package PHPCR
 * @version $Id: PropertyIteratorInterface.php 1811 2009-01-28 12:04:49Z robert $
 */

/**
 * Allows easy iteration through a list of Propertys with nextProperty as
 * well as a skip method.
 *
 * @package PHPCR
 * @version $Id: PropertyIteratorInterface.php 1811 2009-01-28 12:04:49Z robert $
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 */
interface PHPCR_PropertyIteratorInterface extends PHPCR_RangeIteratorInterface {

	/**
	 * Returns the next Property from the iterator.
	 *
	 * @return PHPCR_PropertyInterface
	 * @throws OutOfBoundsException if the iterator contains no more elements.
	 */
	public function nextProperty();

}

?>