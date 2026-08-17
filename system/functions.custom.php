<?php
/**
 * Файл: system/functions.custom.php
 * 
 * Читать инструкцию https://github.com/webitproff/functions.custom.php-cotonti/blob/main/README.md
 * 
 * Пользовательские функции Cotonti, расширяющие стандартный функционал.
 *
 * Список функций:
 *
 * - cot_load_structure_custom()
 *   Загружает структуру категорий из базы данных с учетом иерархии,
 *   дополнительных полей и шаблонов.
 *
 * - forums_url_structure(&$args)
 *   Преобразует параметры URL форумов в человекопонятный вид на основе структуры.
 *
 * - cot_customfuncs_getUserDataOwner()
 *   Возвращает данные пользователя-владельца страницы.
 *
 * - cot_customfuncs_isOwnerSuperAdmin()
 *   Проверяет, является ли владелец страницы суперадминистратором.
 *
 * - cot_customfuncs_isOwnerAdmin()
 *   Проверяет, имеет ли владелец страницы права администратора
 *   (учитывает как основную, так и дополнительные группы).
 *
 * - cot_customfuncs_getOwnerGroupId()
 *   Возвращает ID основной группы владельца страницы.
 *
 * - cot_customfuncs_isOwner()
 *   Проверяет, является ли текущий пользователь владельцем страницы.
 *
 * - cot_customfuncs_isTopicAuthor()
 *   Проверяет, является ли текущий пользователь автором топика форума.
 *
 * - cot_customfuncs_isPageAuthor()
 *   Проверяет, является ли текущий пользователь автором статьи.
 *
 * - cot_customfuncs_getOwnerId()
 *   Возвращает ID пользователя-владельца страницы.
 *
 * - cot_customfuncs_getOwnerName()
 *   Возвращает экранированное имя пользователя-владельца страницы.
 *
 * - cot_customfuncs_getPageOwnerId()
 *   Возвращает ID владельца статьи (страницы) на основе текущего URL.
 *
 * - cot_langfile_custom($name, $type = 'plug', $default = 'en')
 *   Загружает пользовательский языковой файл для модуля или плагина.
 */
 
defined('COT_CODE') or die('Wrong URL');


// Определение глобальных переменных для работы с базой данных, конфигурацией и структурой
global $db, $db_structure, $cfg, $cot_extrafields, $L;
// Объявление глобальной переменной структуры
global $structure;

/**
 * Загружает структуру категорий из базы данных с учетом иерархии и дополнительных полей
 *
 * @return void
 */
function cot_load_structure_custom()
{
    // Доступ к глобальным переменным
    global $db, $db_structure, $cfg, $cot_extrafields, $structure;
    // Инициализация массива структуры
    $structure = [];
    // Инициализация массива подкатегорий
    $subcats = [];

    // Выбор SQL-запроса в зависимости от режима обновления
    if (defined('COT_UPGRADE')) {
        // Запрос для режима обновления, сортировка только по пути
        $sql = $db->query("SELECT * FROM $db_structure ORDER BY COALESCE(structure_path, '') ASC");
    } else {
        // Запрос для обычного режима, сортировка по области и пути
        $sql = $db->query("SELECT * FROM $db_structure ORDER BY structure_area ASC, COALESCE(structure_path, '') ASC");
    }

    // Инициализация массивов для путей, текстовых путей и шаблонов
    $path = [];
    $tpath = [];
    $tpls = [];

    // Обработка каждой записи из результата запроса
    foreach ($sql->fetchAll() as $row) {
        // Пропуск записей с пустым или нестроковым кодом или областью
        if (empty($row['structure_code']) || !is_string($row['structure_code']) || empty($row['structure_area']) || !is_string($row['structure_area'])) {
            continue;
        }

        // Присваивание кода категории
        $row['structure_code'] = $row['structure_code'];
        // Установка пути категории, если не указан — использование кода
        $row['structure_path'] = !empty($row['structure_path']) && is_string($row['structure_path']) ? $row['structure_path'] : $row['structure_code'];
        // Присваивание области категории
        $row['structure_area'] = $row['structure_area'];
        // Установка заголовка, если не указан — пустая строка
        $row['structure_title'] = !empty($row['structure_title']) && is_string($row['structure_title']) ? $row['structure_title'] : '';
        // Установка описания, если не указано — пустая строка
        $row['structure_desc'] = !empty($row['structure_desc']) && is_string($row['structure_desc']) ? $row['structure_desc'] : '';
        // Установка иконки, если не указана — пустая строка
        $row['structure_icon'] = !empty($row['structure_icon']) && is_string($row['structure_icon']) ? $row['structure_icon'] : '';
        // Приведение флага блокировки к целому числу
        $row['structure_locked'] = isset($row['structure_locked']) ? (int)$row['structure_locked'] : 0;
        // Приведение счетчика к целому числу
        $row['structure_count'] = isset($row['structure_count']) ? (int)$row['structure_count'] : 0;
        // Установка шаблона, если не указан — использование кода
        $row['structure_tpl'] = !empty($row['structure_tpl']) && is_string($row['structure_tpl']) ? $row['structure_tpl'] : $row['structure_code'];
        // Приведение идентификатора к целому числу
        $row['structure_id'] = isset($row['structure_id']) ? (int)$row['structure_id'] : 0;

        // Поиск последней точки в пути
        $last_dot = mb_strrpos($row['structure_path'], '.');

        // Обработка иерархического пути
        if ($last_dot !== false) {
            // Извлечение родительского пути
            $path1 = mb_substr($row['structure_path'], 0, $last_dot);
            // Формирование полного пути
            $path[$row['structure_path']] = !empty($path[$path1]) ? $path[$path1] . '.' . $row['structure_code'] : $row['structure_code'];
            // Определение разделителя для текстового пути
            $separator = (strip_tags($cfg['separator']) === $cfg['separator']) ? ' ' . $cfg['separator'] . ' ' : ' \ ';
            // Формирование текстового пути
            $tpath[$row['structure_path']] = !empty($tpath[$path1]) ? $tpath[$path1] . $separator . $row['structure_title'] : $row['structure_title'];
            // Определение родительской категории
            $parent_dot = mb_strrpos($path[$path1] ?? '', '.');
            $parent = ($parent_dot !== false) ? mb_substr($path[$path1], $parent_dot + 1) : ($path[$path1] ?? $row['structure_code']);
            // Добавление кода категории в массив подкатегорий
            $subcats[$row['structure_area']][$parent][] = $row['structure_code'];
        } else {
            // Установка пути для корневой категории
            $path[$row['structure_path']] = $row['structure_code'];
            // Установка текстового пути для корневой категории
            $tpath[$row['structure_path']] = $row['structure_title'];
            // Установка родительской категории
            $parent = $row['structure_code'];
        }

        // Обработка шаблона, если указано 'same_as_parent'
        if ($row['structure_tpl'] === 'same_as_parent') {
            // Использование шаблона родителя или кода категории
            $row['structure_tpl'] = $tpls[$parent] ?? $row['structure_code'];
        }

        // Сохранение шаблона для категории
        $tpls[$row['structure_code']] = $row['structure_tpl'];

        // Формирование структуры данных категории
        $structure[$row['structure_area']][$row['structure_code']] = [
            // Путь категории
            'path' => $path[$row['structure_path']],
            // Текстовый путь категории
            'tpath' => $tpath[$row['structure_path']],
            // Исходный путь категории
            'rpath' => $row['structure_path'],
            // Идентификатор категории
            'id' => $row['structure_id'],
            // Шаблон категории
            'tpl' => $row['structure_tpl'],
            // Заголовок категории
            'title' => $row['structure_title'],
            // Описание категории
            'desc' => $row['structure_desc'],
            // Иконка категории
            'icon' => $row['structure_icon'],
            // Флаг блокировки
            'locked' => $row['structure_locked'],
            // Счетчик элементов
            'count' => $row['structure_count'],
            // Подкатегории
            'subcats' => $subcats[$row['structure_area']][$row['structure_code']] ?? []
        ];

        // Обработка дополнительных полей, если они существуют
        if (!empty($cot_extrafields[$db_structure])) {
            // Перебор дополнительных полей
            foreach ($cot_extrafields[$db_structure] as $exfld) {
                // Формирование имени поля
                $fieldName = 'structure_' . $exfld['field_name'];
                // Добавление значения дополнительного поля в структуру
                $structure[$row['structure_area']][$row['structure_code']][$exfld['field_name']] = $row[$fieldName] ?? null;
            }
        }
    }

    // Финальная проверка и фильтрация структуры
    foreach ($structure as $area => &$area_structure) {
        // Проверка, что структура области является массивом
        if (!is_array($area_structure)) {
            // Инициализация пустого массива для невалидной области
            $area_structure = [];
            continue;
        }
        // Проверка каждой записи в области
        foreach ($area_structure as $i => &$x) {
            // Пропуск невалидных записей
            if (!is_array($x) || empty($x['path']) || !is_string($x['path'])) {
                unset($area_structure[$i]);
                continue;
            }
            // Установка подкатегорий
            $x['subcats'] = $subcats[$area][$i] ?? [];
            // Присваивание пути
            $x['path'] = $x['path'];
            // Установка текстового пути, если не указан — использование кода
            $x['tpath'] = !empty($x['tpath']) && is_string($x['tpath']) ? $x['tpath'] : $i;
            // Установка исходного пути, если не указан — использование кода
            $x['rpath'] = !empty($x['rpath']) && is_string($x['rpath']) ? $x['rpath'] : $i;
            // Установка заголовка, если не указан — пустая строка
            $x['title'] = !empty($x['title']) && is_string($x['title']) ? $x['title'] : '';
            // Установка описания, если не указано — пустая строка
            $x['desc'] = !empty($x['desc']) && is_string($x['desc']) ? $x['desc'] : '';
            // Установка иконки, если не указана — пустая строка
            $x['icon'] = !empty($x['icon']) && is_string($x['icon']) ? $x['icon'] : '';
            // Приведение счетчика к целому числу
            $x['count'] = isset($x['count']) ? (int)$x['count'] : 0;
            // Приведение флага блокировки к целому числу
            $x['locked'] = isset($x['locked']) ? (int)$x['locked'] : 0;
            // Установка шаблона, если не указан — использование кода
            $x['tpl'] = !empty($x['tpl']) && is_string($x['tpl']) ? $x['tpl'] : $i;
            // Приведение идентификатора к целому числу
            $x['id'] = isset($x['id']) ? (int)$x['id'] : 0;
        }
        // Освобождение ссылки на последнюю запись
        unset($x);
    }
    // Освобождение ссылки на последнюю область
    unset($area_structure);

    // Сохранение копии структуры перед выполнением плагинов
    $temp_structure = $structure;
    // Выполнение плагинов, подключенных к событию structure
    foreach (cot_getextplugins('structure') as $pl) {
        // Восстановление структуры перед выполнением плагина
        $structure = $temp_structure;
        // Подключение файла плагина
        include $pl;
        // Проверка структуры после выполнения плагина
        foreach ($structure as $area => &$area_structure) {
            // Проверка, что структура области является массивом
            if (!is_array($area_structure)) {
                // Инициализация пустого массива для невалидной области
                $area_structure = [];
                continue;
            }
            // Проверка каждой записи в области
            foreach ($area_structure as $i => &$x) {
                // Пропуск невалидных записей
                if (!is_array($x) || empty($x['path']) || !is_string($x['path'])) {
                    unset($area_structure[$i]);
                    continue;
                }
            }
            // Освобождение ссылки на последнюю запись
            unset($x);
        }
        // Освобождение ссылки на последнюю область
        unset($area_structure);
    }
    // Финальное восстановление структуры
    $structure = $temp_structure;
}

function forums_url_structure(&$args)
{
    global $cfg, $db, $structure, $db_forum_topics, $db_forum_posts;

    require_once cot_incfile('forums', 'module');

    $script = 'forums';
    $replacement = '';

    if (isset($args['m']) && $args['m'] === 'topics') {
        if (isset($args['s'])) {
            $d = isset($args['d']) ? (int) $args['d'] : 0;
            $replacement .= str_replace('.', '/', $structure['forums'][$args['s']]['path'] ?? '');

            if (isset($args['d'])) {
                $replacement .= '/page' . $d;
            }

            unset($args['d'], $args['s']);
        } else {
            $replacement .= $script;
        }
    } elseif (isset($args['m']) && $args['m'] === 'posts') {
        if (isset($args['q'])) {
            $q = (int) $args['q'];
            $d = isset($args['d']) ? (int) $args['d'] : 0;
            $s = $db->query("SELECT fp_cat FROM $db_forum_posts WHERE fp_topicid = $q")->fetchColumn();

            if ($s !== false) {
                $replacement .= str_replace('.', '/', $structure['forums'][$s]['path'] ?? '') . '/topic' . $q;
            } else {
                $replacement .= $script;
            }

            if (isset($args['d'])) {
                $replacement .= '/page' . $d;
            }

            unset($args['d'], $args['q'], $args['m']);
        } elseif (isset($args['id'])) {
            $id = (int) $args['id'];
            $s = $db->query("SELECT fp_cat FROM $db_forum_posts WHERE fp_id = $id")->fetchColumn();

            if ($s !== false) {
                $replacement .= str_replace('.', '/', $structure['forums'][$s]['path'] ?? '') . '/post' . $id;
            } else {
                $replacement .= $script;
            }

            unset($args['id'], $args['m']);
        } else {
            $replacement .= $script;
        }
    } else {
        $replacement .= $script;
    }

    return $replacement;
}

/**
 * Получает данные пользователя-владельца страницы. В шаблонах напрямую не вызывать!!
 *
 * Эта функция определяет владельца страницы на основе параметров 'id' или 'u' из URL.
 * Если параметры отсутствуют, она использует ID текущего авторизованного пользователя.
 *
 * @return array|null Массив данных пользователя или null, если пользователь не найден.
 *
 * Пример использования в PHP:
 * $owner = cot_customfuncs_getUserDataOwner();
 * if (!empty($owner)) {
 *     echo $owner['user_id'];    // например, 123
 *     echo $owner['user_name'];  // например, "Alice"
 *     echo $owner['user_email']; // например, "alice@example.com"
 * } else {
 *     echo 'Пользователь не найден';
 * }
 *
 * Пример возвращаемого значения:
 * При URL ?id=123 и существующем пользователе вернётся:
 * [
 *     'user_id' => 123,
 *     'user_name' => 'Alice',
 *     'user_email' => 'alice@example.com',
 *     'user_regdate' => 1700000000,
 *     // ... остальные поля таблицы cot_users
 * ]
 *
 * При отсутствии параметров и неавторизованном пользователе вернётся null.
 *
 * Для вывода в шаблоне используйте обёртки:
 * cot_customfuncs_getOwnerId()  — возвращает ID владельца.
 * cot_customfuncs_getOwnerName() — возвращает имя владельца.
 */
function cot_customfuncs_getUserDataOwner()
{
    // Импортируем из GET-параметра 'id' число (INT), это ID пользователя
    $id = cot_import('id', 'G', 'INT');
    // Импортируем из GET-параметра 'u' строку (TXT), это username пользователя
    $u = cot_import('u', 'G', 'TXT');

    // Если передан username, но не передан id
    if (!empty($u) && empty($id)) {
        // Выполняем запрос в БД: выбираем user_id из таблицы пользователей, где user_name совпадает с $u
        $id = Cot::$db->query(
            'SELECT user_id FROM ' . Cot::$db->users . ' WHERE user_name = ? LIMIT 1',
            [$u]
        )->fetchColumn();
    }
    // Если не передан ни id, ни username, но текущий юзер авторизован
    elseif (empty($id) && empty($u) && Cot::$usr['id'] > 0) {
        // Тогда используем id текущего авторизованного пользователя
        $id = Cot::$usr['id'];
    }

    // Проверяем существование пользователя в БД по полученному id и возвращаем его данные
    if ($id > 0) {
        return Cot::$db->query(
            'SELECT * FROM ' . Cot::$db->users . ' WHERE user_id = ? LIMIT 1',
            [$id]
        )->fetch();
    }

    return null;
}

/**
 * Проверяет, является ли владелец страницы суперадминистратором.
 *
 * Функция использует cot_customfuncs_getUserDataOwner() для получения данных владельца
 * и сравнивает его основную группу с группой суперадминистратора.
 *
 * @return bool True, если владелец страницы найден и его user_maingrp == COT_GROUP_SUPERADMINS.
 *
 * Примеры использования в шаблонах:
 * <!-- IF {PHP|cot_customfuncs_isOwnerSuperAdmin()} -->
 *     <div>Владелец страницы — суперадмин</div>
 * <!-- ENDIF -->
 *
 * <!-- IF !{PHP|cot_customfuncs_isOwnerSuperAdmin()} -->
 *     <div>Владелец страницы не является суперадмином</div>
 * <!-- ENDIF -->
 */
function cot_customfuncs_isOwnerSuperAdmin()
{
    $ownerData = cot_customfuncs_getUserDataOwner();

    // Проверяем, что данные получены и основная группа равна группе суперадминов
    if (!empty($ownerData) && isset($ownerData['user_maingrp'])) {
        return (int)$ownerData['user_maingrp'] === COT_GROUP_SUPERADMINS;
    }

    return false;
}

/**
 * Проверяет, имеет ли владелец страницы права администратора.
 *
 * Функция получает данные владельца через cot_customfuncs_getUserDataOwner(),
 * строит его матрицу прав с помощью cot_auth_build() и проверяет наличие
 * права 'A' в области 'admin'.
 *
 * Это корректно определяет:
 * - суперадминистраторов (COT_GROUP_SUPERADMINS);
 * - пользователей, состоящих в дополнительной группе администраторов.
 *
 * @return bool True, если владелец страницы — администратор, иначе False.
 *
 * Примеры использования в шаблонах:
 * 1. Показать бейдж, если владелец — админ:
 * <!-- IF {PHP|cot_customfuncs_isOwnerAdmin()} -->
 *     <span class="badge bg-success">Владелец — администратор</span>
 * <!-- ENDIF -->
 *
 * 2. Показать сообщение для обычного владельца:
 * <!-- IF !{PHP|cot_customfuncs_isOwnerAdmin()} -->
 *     <span class="badge bg-secondary">Владелец — обычный пользователь</span>
 * <!-- ENDIF -->
 *
 * 3. Использовать в условии с текущим пользователем:
 * <!-- IF {PHP.usr.id} > 0 AND {PHP|cot_customfuncs_isOwnerAdmin()} -->
 *     <div>Вы видите страницу администратора.</div>
 * <!-- ENDIF -->
 *
 * 4. Вывести статус (для отладки):
 * Владелец админ: {PHP|cot_customfuncs_isOwnerAdmin()}
 *
 * Примеры возвращаемых значений:
 * - Владелец найден и имеет права администратора → true.
 * - Владелец найден, но не администратор → false.
 * - Владелец не найден (ID=0) → false.
 */
function cot_customfuncs_isOwnerAdmin()
{
    // Получаем данные владельца страницы
    $ownerData = cot_customfuncs_getUserDataOwner();

    // Если данные отсутствуют или нет ID, возвращаем false
    if (empty($ownerData['user_id'])) {
        return false;
    }

    $ownerId = (int)$ownerData['user_id'];
    $maingrp = (int)$ownerData['user_maingrp'];

    // Строим ACL для владельца.
    // cot_auth_build() возвращает массив:
    // $rights['admin']['a'] = битовая маска прав.
    $rights = cot_auth_build($ownerId, $maingrp);

    // Если права администратора не заданы, возвращаем false
    if (!isset($rights['admin']['a'])) {
        return false;
    }

    // Получаем битовую маску прав администратора
    $adminRights = (int)$rights['admin']['a'];

    // Бит 'A' (администратор) равен 128 (по определению в cot_auth()).
    // Проверяем, установлен ли этот бит.
    return ($adminRights & 128) === 128;
}

/**
 * Получает ID основной группы владельца страницы.
 *
 * Функция-обёртка для использования в шаблонах Cotonti.
 * Она вызывает cot_customfuncs_getUserDataOwner() и возвращает числовой
 * идентификатор основной группы владельца (user_maingrp).
 *
 * Может использоваться для проверки принадлежности владельца к конкретной группе,
 * например, к суперадминистраторам (COT_GROUP_SUPERADMINS = 5).
 * Для проверки именно прав администратора рекомендуется использовать
 * функцию cot_customfuncs_isOwnerAdmin(), которая учитывает дополнительные группы.
 *
 * @return int ID основной группы владельца или 0, если владелец не найден.
 *
 * Примеры использования в шаблонах:
 * 1. Вывести ID основной группы:
 * Группа владельца: {PHP|cot_customfuncs_getOwnerGroupId()}
 *
 * 2. Проверить, является ли владелец суперадмином:
 * <!-- IF {PHP|cot_customfuncs_getOwnerGroupId()} == 5 -->
 *     Владелец — суперадмин
 * <!-- ENDIF -->
 *
 * 3. Сравнить группу владельца с группой текущего пользователя:
 * <!-- IF {PHP|cot_customfuncs_getOwnerGroupId()} == {PHP.usr.maingrp} -->
 *     Вы в одной группе с владельцем.
 * <!-- ENDIF -->
 *
 * Примеры возвращаемых значений:
 * - Владелец найден, user_maingrp = 5 → 5.
 * - Владелец найден, user_maingrp = 4 → 4.
 * - Владелец не найден → 0.
 */
function cot_customfuncs_getOwnerGroupId()
{
    $ownerData = cot_customfuncs_getUserDataOwner();

    return !empty($ownerData['user_maingrp'])
        ? (int)$ownerData['user_maingrp']
        : 0;
}
/**
 * Проверяет, является ли текущий пользователь владельцем страницы.
 *
 * Эта функция использует cot_customfuncs_getUserDataOwner() для получения данных владельца
 * и сравнивает его ID с ID текущего авторизованного пользователя.
 *
 * @return bool True, если текущий пользователь — владелец страницы, иначе False.
 *
 * Примеры использования в шаблонах:
 * 1. Показать блок только владельцу:
 * <!-- IF {PHP|cot_customfuncs_isOwner()} -->
 *     <div>Вы владелец этой страницы</div>
 * <!-- ENDIF -->
 *
 * 2. Инвертированное условие:
 * <!-- IF !{PHP|cot_customfuncs_isOwner()} -->
 *     <div>Это не ваш профиль</div>
 * <!-- ENDIF -->
 *
 * 3. Совместная проверка с авторизацией:
 * <!-- IF {PHP.usr.id} > 0 AND {PHP|cot_customfuncs_isOwner()} -->
 *     <button>Сохранить изменения</button>
 * <!-- ENDIF -->
 *
 * 4. Вывести булево значение (для отладки):
 * Статус владельца: {PHP|cot_customfuncs_isOwner()}
 *
 * Примеры возвращаемых значений:
 * - Текущий пользователь ID=123 и страница принадлежит пользователю ID=123 → true.
 * - Текущий пользователь ID=124 и страница принадлежит пользователю ID=123 → false.
 * - Пользователь не авторизован (ID=0) → false.
 */
function cot_customfuncs_isOwner()
{
    // Получаем данные владельца страницы с помощью вспомогательной функции
    $ownerData = cot_customfuncs_getUserDataOwner();

    // Возвращаем true, если данные найдены и ID владельца совпадает с ID текущего пользователя
    if (!empty($ownerData) && Cot::$usr['id'] > 0 && Cot::$usr['id'] == $ownerData['user_id']) {
        return true;
    }

    // Если условия не выполнены, возвращаем false
    return false;
}

/**
 * Проверяет, является ли текущий пользователь автором топика.
 *
 * Функция получает ID топика из URL-параметра 'q' и сравнивает его автора
 * с текущим авторизованным пользователем.
 *
 * @return bool True, если текущий пользователь — автор топика, иначе False.
 *
 * Примеры использования в шаблонах:
 * 1. Показать бейдж автора:
 * <!-- IF {PHP|cot_customfuncs_isTopicAuthor()} -->
 *     <span class="badge bg-info">Вы автор</span>
 * <!-- ENDIF -->
 *
 * 2. Скрыть кнопку для неавтора:
 * <!-- IF !{PHP|cot_customfuncs_isTopicAuthor()} -->
 *     <button>Подписаться на тему</button>
 * <!-- ENDIF -->
 *
 * 3. Комбинация с проверкой авторизации:
 * <!-- IF {PHP.usr.id} > 0 AND {PHP|cot_customfuncs_isTopicAuthor()} -->
 *     <div>Вы можете удалить эту тему.</div>
 * <!-- ENDIF -->
 *
 * 4. Вывести статус (для отладки):
 * Автор темы: {PHP|cot_customfuncs_isTopicAuthor()}
 *
 * Примеры возвращаемых значений:
 * - URL ?q=5, топик ft_id=5 имеет ft_firstposterid=123, текущий пользователь ID=123 → true.
 * - URL ?q=5, топик ft_id=5 имеет ft_firstposterid=123, текущий пользователь ID=124 → false.
 * - Параметр 'q' отсутствует или равен 0 → false.
 */
function cot_customfuncs_isTopicAuthor()
{
    // Объявляем глобальную переменную, содержащую имя таблицы форумов.
    global $db_forum_topics;

    // Импортируем ID топика напрямую из URL-параметра 'q'.
    $topicId = cot_import('q', 'G', 'INT');

    // Проверяем, авторизован ли пользователь, и передан ли ID топика.
    if (!Cot::$usr || Cot::$usr['id'] <= 0 || empty($topicId)) {
        return false;
    }

    // Выполняем запрос в БД.
    $posterId = Cot::$db->query(
        "SELECT ft_firstposterid FROM $db_forum_topics WHERE ft_id = ? LIMIT 1",
        [$topicId]
    )->fetchColumn();

    // Возвращаем true, если ID автора топика совпадает с ID текущего пользователя.
    if ($posterId > 0 && Cot::$usr['id'] == $posterId) {
        return true;
    }

    return false;
}

/**
 * Проверяет, является ли текущий пользователь автором статьи.
 *
 * Функция получает ID статьи из URL-параметра 'id' и сравнивает его автора
 * с текущим авторизованным пользователем.
 *
 * @return bool True, если текущий пользователь — автор статьи, иначе False.
 *
 * Примеры использования в шаблонах:
 * 1. Показать бейдж автора:
 * <!-- IF {PHP|cot_customfuncs_isPageAuthor()} -->
 *     <span class="badge bg-info">Вы автор этой статьи</span>
 * <!-- ENDIF -->
 *
 * 2. Скрыть предупреждение для неавтора:
 * <!-- IF !{PHP|cot_customfuncs_isPageAuthor()} -->
 *     <div>Вы не являетесь автором этой страницы.</div>
 * <!-- ENDIF -->
 *
 * 3. Показать элементы управления только автору:
 * <!-- IF {PHP|cot_customfuncs_isPageAuthor()} -->
 *     <button>Удалить статью</button>
 * <!-- ENDIF -->
 *
 * 4. Комбинация с авторизацией:
 * <!-- IF {PHP.usr.id} > 0 AND {PHP|cot_customfuncs_isPageAuthor()} -->
 *     <div>Вы можете управлять этой статьёй.</div>
 * <!-- ENDIF -->
 *
 * 5. Вывести статус (для отладки):
 * Автор статьи: {PHP|cot_customfuncs_isPageAuthor()}
 *
 * Примеры возвращаемых значений:
 * - URL ?id=42, страница page_id=42 имеет page_ownerid=123, текущий пользователь ID=123 → true.
 * - URL ?id=42, страница page_id=42 имеет page_ownerid=123, текущий пользователь ID=124 → false.
 * - Параметр 'id' отсутствует или равен 0 → false.
 */
function cot_customfuncs_isPageAuthor()
{
    // Объявляем глобальную переменную, содержащую имя таблицы статей.
    global $db_pages;

    // Импортируем ID статьи напрямую из URL-параметра 'id'.
    $pageId = cot_import('id', 'G', 'INT');

    // Проверяем, авторизован ли пользователь, и передан ли ID статьи.
    if (!Cot::$usr || Cot::$usr['id'] <= 0 || empty($pageId)) {
        return false;
    }

    // Выполняем запрос к БД.
    $ownerId = Cot::$db->query(
        "SELECT page_ownerid FROM $db_pages WHERE page_id = ? LIMIT 1",
        [$pageId]
    )->fetchColumn();

    // Возвращаем true, если ID владельца статьи совпадает с ID текущего пользователя.
    if ($ownerId > 0 && Cot::$usr['id'] == $ownerId) {
        return true;
    }

    return false;
}

/**
 * Получает ID пользователя-владельца страницы.
 *
 * Функция-обёртка для использования в шаблонах Cotonti. Она вызывает
 * cot_customfuncs_getUserDataOwner() и возвращает числовой ID владельца профиля.
 *
 * @return int ID владельца или 0, если владелец не найден.
 *
 * Примеры использования в шаблонах:
 * 1. Вывести ID владельца:
 * ID владельца: {PHP|cot_customfuncs_getOwnerId()}
 *
 * 2. Проверить, найден ли владелец:
 * <!-- IF {PHP|cot_customfuncs_getOwnerId()} > 0 -->
 *     <p>Профиль принадлежит зарегистрированному пользователю.</p>
 * <!-- ENDIF -->
 *
 * 3. Сравнить с текущим пользователем:
 * <!-- IF {PHP|cot_customfuncs_getOwnerId()} == {PHP.usr.id} -->
 *     <p>Вы владелец.</p>
 * <!-- ENDIF -->
 *
 * 4. Использовать в условии с авторизацией:
 * <!-- IF {PHP.usr.id} > 0 AND {PHP|cot_customfuncs_getOwnerId()} == {PHP.usr.id} -->
 *     <button>Редактировать</button>
 * <!-- ENDIF -->
 *
 * Примеры возвращаемых значений:
 * - Владелец найден (ID=123) → 123.
 * - Владелец не найден → 0.
 */
function cot_customfuncs_getOwnerId()
{
    // Получаем массив данных владельца страницы
    $owner = cot_customfuncs_getUserDataOwner();

    // Если в массиве есть user_id, возвращаем его как целое число, иначе 0
    return !empty($owner['user_id']) ? (int)$owner['user_id'] : 0;
}

/**
 * Получает имя пользователя-владельца страницы.
 *
 * Функция-обёртка для использования в шаблонах Cotonti. Она вызывает
 * cot_customfuncs_getUserDataOwner() и возвращает экранированное имя владельца профиля.
 *
 * @return string Имя владельца или пустая строка, если владелец не найден.
 *
 * Примеры использования в шаблонах:
 * 1. Вывести имя владельца:
 * Имя владельца: {PHP|cot_customfuncs_getOwnerName()}
 *
 * 2. Условно показать блок, если имя непустое:
 * <!-- IF {PHP|cot_customfuncs_getOwnerName()} != '' -->
 *     <p>Владелец: {PHP|cot_customfuncs_getOwnerName()}</p>
 * <!-- ENDIF -->
 *
 * 3. Использовать в атрибуте title:
 * <a title="{PHP|cot_customfuncs_getOwnerName()}">Профиль</a>
 *
 * 4. Проверить наличие имени:
 * <!-- IF {PHP|cot_customfuncs_getOwnerName()} -->
 *     <p>Имя владельца задано.</p>
 * <!-- ELSE -->
 *     <p>Имя владельца не указано.</p>
 * <!-- ENDIF -->
 *
 * Примеры возвращаемых значений:
 * - Имя найдено "Alice" → возвращает "Alice".
 * - Владелец не найден или имя пустое → возвращает ''.
 */
function cot_customfuncs_getOwnerName()
{
    // Получаем массив данных владельца страницы
    $owner = cot_customfuncs_getUserDataOwner();

    // Если в массиве есть user_name, возвращаем его, экранируя HTML-сущности,
    // иначе возвращаем пустую строку
    return !empty($owner['user_name']) ? htmlspecialchars($owner['user_name']) : '';
}

/**
 * Получает ID пользователя-владельца статьи (страницы).
 *
 * Функция-обёртка для использования в шаблонах Cotonti. Она выполняет запрос
 * к базе данных и возвращает числовой ID автора статьи на основе текущего URL.
 * Может использоваться как самостоятельная проверка или в сочетании с
 * cot_customfuncs_isPageAuthor() для определения авторства.
 *
 * @return int ID автора статьи или 0, если статья или автор не найдены.
 *
 * Примеры использования в шаблонах:
 * 1. Вывести ID автора:
 * ID автора: {PHP|cot_customfuncs_getPageOwnerId()}
 *
 * 2. Проверить, найден ли автор:
 * <!-- IF {PHP|cot_customfuncs_getPageOwnerId()} > 0 -->
 *     <p>Автор найден.</p>
 * <!-- ENDIF -->
 *
 * 3. Сравнить с текущим пользователем:
 * <!-- IF {PHP|cot_customfuncs_getPageOwnerId()} == {PHP.usr.id} -->
 *     <p>Вы автор этой статьи.</p>
 * <!-- ELSE -->
 *     <p>Вы не являетесь автором.</p>
 * <!-- ENDIF -->
 *
 * 4. Использовать в условии с авторизацией:
 * <!-- IF {PHP.usr.id} > 0 AND {PHP|cot_customfuncs_getPageOwnerId()} == {PHP.usr.id} -->
 *     <button>Редактировать</button>
 * <!-- ENDIF -->
 *
 * Примеры возвращаемых значений:
 * - Статья найдена, ownerid=42 → 42.
 * - Статья не найдена или ownerid пуст → 0.
 */
function cot_customfuncs_getPageOwnerId()
{
    // Объявляем глобальную переменную, содержащую имя таблицы страниц.
    global $db_pages;

    // Импортируем ID статьи напрямую из URL-параметра 'id'.
    $pageId = cot_import('id', 'G', 'INT');

    // Выполняем запрос к БД: получаем ID владельца страницы по ID страницы.
    $ownerId = Cot::$db->query(
        "SELECT page_ownerid FROM $db_pages WHERE page_id = ? LIMIT 1",
        [$pageId]
    )->fetchColumn();

    // Возвращаем ID автора, приведённый к целому числу, либо 0, если данных нет.
    return !empty($ownerId) ? (int)$ownerId : 0;
}

// ====================================================================
// * Пользовательские файлы локализации для штатных модулей и плагинов Cotonti
// * Инструкция: https://abuyfile.com/ru/usersblog/cot-langfile-custom-cotonti
// ====================================================================
/**
 * Загружает пользовательский языковой файл для модуля или плагина.
 *
 * Ищет файл с именем `{name}.custom.{lang}.lang.php` в директории `lang/` расширения.
 * Если файл для текущего языка не найден, пытается загрузить файл для языка по умолчанию (обычно 'en').
 *
 * @param string $name    Имя расширения (например, 'page', 'aliaspagepro').
 * @param string $type    Тип расширения: 'module' или 'plug'. По умолчанию 'plug'.
 * @param string $default Код языка по умолчанию, если `$lang` не задан или файл локализации отсутствует. По умолчанию 'en'.
 *
 * @return bool True, если пользовательский языковой файл был успешно загружен, иначе false.
 */
function cot_langfile_custom($name, $type = 'plug', $default = 'en')
{
    // Получаем глобальные переменные конфигурации и текущий язык интерфейса
    global $cfg, $lang, $L;

    // Определяем код языка: используем текущий язык интерфейса или fallback по умолчанию
    $langCode = isset($lang) ? $lang : $default;

    // Формируем путь к директории lang расширения
    if ($type === 'module') {
        $dir = $cfg['modules_dir'] . '/' . $name . '/lang';
    } else {
        $dir = $cfg['plugins_dir'] . '/' . $name . '/lang';
    }

    // Основной файл для текущего языка
    $file = $dir . '/' . $name . '.custom.' . $langCode . '.lang.php';

    // Если файл существует и доступен для чтения, загружаем его
    if (is_file($file) && is_readable($file)) {
        include $file;
        return true;
    }

    // Если текущий язык не является языком по умолчанию, пробуем загрузить файл языка по умолчанию
    if ($langCode !== $default) {
        $fallback = $dir . '/' . $name . '.custom.' . $default . '.lang.php';
        if (is_file($fallback) && is_readable($fallback)) {
            include $fallback;
            return true;
        }
    }

    // Пользовательский языковой файл не найден
    return false;
}
// ====================================================================
// * Пользовательские файлы локализации для штатных модулей и плагинов Cotonti
// * Инструкция: https://abuyfile.com/ru/usersblog/cot-langfile-custom-cotonti
// ====================================================================