<?php

/**
 * AiryMVC Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license.
 *
 * It is also available at this URL: http://opensource.org/licenses/BSD-3-Clause
 * The project website URL: https://code.google.com/p/airymvc/
 *
 * @author: Hung-Fu Aaron Chang
 */

require_once '../html/components/FieldElement.php/FieldElement.php';
require_once '../../components/InputType.phpnents/InputType.php';
/**
 * Description of HiddenElement
 *
 * @author Hung-Fu Aaron Chang
 */
class HiddenElement extends FieldElement{
    //put your code here
    protected $_type = InputType::HIDDEN;
    
    public function __construct($id)
    {
        $this->setId($id);
        $this->setAttribute("type", InputType::HIDDEN);
    }
}


?>
