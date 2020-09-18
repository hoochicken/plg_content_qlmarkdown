<?php
/**
 * @package        plg_content_qlmarkdown
 * @copyright      Copyright (C) 2020 ql.de All rights reserved.
 * @author         Mareike Riegel mareike.riegel@ql.de
 * @license        GNU General Public License version 2 or later; see LICENSE.txt
 */

//no direct access
defined('_JEXEC') or die ('Restricted Access');
/** @var int $intCounter */
/** @var string $class */
/** @var string $content */
/** @var string $style */
/** @var string $type */
/** @var string $title */
?>

<div id="<?php echo $id; ?>"
     class="qlmarkdown <?php echo $class; ?>"
     style="<?php echo $style; ?>">
    <?php echo $content; ?>
</div>
