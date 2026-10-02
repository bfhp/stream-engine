ALTER TABLE `menu`
  ADD COLUMN `group_order` int(10) unsigned NOT NULL DEFAULT 0 AFTER `sort_order`,
  ADD COLUMN `enabled` tinyint(1) NOT NULL DEFAULT 1 AFTER `group_order`,
  ADD KEY `menu_group_order_index` (`group_order`,`menu_group`,`sort_order`,`id`);

SET @menu_group_order := 0;
UPDATE `menu` AS target
INNER JOIN (
  SELECT `menu_group`, (@menu_group_order := @menu_group_order + 10) AS `position`
  FROM (SELECT DISTINCT `menu_group` FROM `menu` ORDER BY `menu_group`) AS groups_in_order
) AS ordered_groups ON ordered_groups.`menu_group` = target.`menu_group`
SET target.`group_order` = ordered_groups.`position`;
