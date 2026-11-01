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
 *
 * - cot_tpl_url($base, $type = 'module', $admin = null)
 *   Возвращает абсолютный URL к файлу шаблона по его имени в параметрах,
 *   например, {PHP|cot_tpl_url('forums.editpost')}
 *
 * - cot_debug_tpl_url()
 *   Возвращает абсолютный URL текущего файла шаблона.
 */
 
defined('COT_CODE') or die('Wrong URL');


// Определение глобальных переменных для работы с базой данных, конфигурацией и структурой
global $db, $db_structure, $cfg, $cot_extrafields, $L;
// Объявление глобальной переменной структуры
global $structure;


/**
 * Загружает полное дерево категорий структуры из БД в глобальный массив $structure.
 *
 * Переопределяет стандартную функцию ядра cot_load_structure() и добавляет:
 *  - кеширование собранного дерева в Cot::$cache->db с версионным ключом и TTL;
 *  - обратный индекс subcats (area → родитель → [дети]) — требуется кастомными
 *    модулями (например, market), которые не используют cot_structure_children();
 *  - защиту от битых данных (пустой/нестроковый structure_code) с логированием;
 *  - graceful degradation при отсутствии родителя (категория становится корнем);
 *  - вызов хука 'structure' внутри цикла по строкам — как в стандартном Cotonti.
 *
 * ОЖИДАНИЯ ОТ ОКРУЖЕНИЯ:
 *  - global $db_structure  — имя таблицы структуры (например, 'x92374_structure');
 *  - global $cot_extrafields — реестр доп. полей; ключ [$db_structure] может быть null;
 *  - global $structure      — целевой массив; пишем в него, а не в Cot::$structure,
 *                             чтобы плагины с 'global $structure;' в хуке 'structure'
 *                             видели актуальные данные;
 *  - Cot::$cache            — объект кеша; может быть null или не иметь ->db;
 *  - Cot::$cfg['separator'] — разделитель для построения tpath.
 *
 * ВЕРСИОНИРОВАНИЕ КЕША:
 *  Ключ кеша содержит $cacheVersion. При изменении формата $structure
 *  (добавление/переименование/удаление ключей категории) ОБЯЗАТЕЛЬНО
 *  увеличивайте $cacheVersion, иначе старый кеш будет прочитан и вернёт
 *  устаревшую структуру. v1 — без 'subcats', v2 — с 'subcats'.
 *
 * ИНВАЛИДАЦИЯ:
 *  Точечно: Cot::$cache->db->remove('structure_all_v2', 'system');
 *  Полностью: Cot::$cache->clear();
 *  При вызове API cot_structure_add()/update()/delete() из своего кода
 *  инвалидацию выполняет вызывающий, а не эта функция.
 *
 * @return void
 */
function cot_load_structure_custom()
{
    // Объявляем глобальные переменные, которые используем ниже.
    // $structure — это целевой массив; пишем в него, а не в Cot::$structure.
    // Cot::$structure и $structure связаны по ссылке в Cot::init(), поэтому
    // плагины, делающие 'global $structure;' в хуке 'structure', увидят изменения.
    global $db_structure, $cot_extrafields, $structure;

    // Версия формата массива $structure. Менять при изменении состава ключей.
    $cacheVersion = 2;

    // Время жизни кеша в секундах (24 часа). По истечении кеш будет перестроен из БД.
    $cacheTtl = 86400;

    // Итоговый ключ кеша: включает версию, чтобы старый формат не читался как новый.
    $cacheKey = 'structure_all_v' . $cacheVersion;

    // ================================ 1. Кеш ================================
    // Если кеш доступен и в нём лежит непустой массив — берём оттуда и выходим.
    // Проверяем и Cot::$cache (объект), и Cot::$cache->db (драйвер БД).
    if (Cot::$cache && Cot::$cache->db) {

        // Читаем значение из пространства 'system' (общий кеш сайта).
        $cached = Cot::$cache->db->get($cacheKey, 'system');

        // is_array + !empty — защита от мусора в кеше (false, null, повреждённая строка).
        if (is_array($cached) && !empty($cached)) {

            // Присваиваем глобальному $structure — это и есть выход из функции.
            $structure = $cached;

            // Возврат без построения дерева из БД.
            return;
        }
    }

    // ============================ 2. Сборка дерева ============================
    // Обнуляем целевой массив: даже если он был частично заполнен выше, начинаем чисто.
    $structure = [];

    // $path[rpath] = путь из КОДОВ (например, 'electronics.smartphones').
    // Нужен для построения иерархии и для cot_structure_children()/parents().
    $path = [];

    // $tpath[rpath] = путь из ЗАГОЛОВКОВ (например, 'Electronics / Smartphones').
    // Нужен для готовых хлебных крошек без дополнительного построения.
    $tpath = [];

    // $tpls[code] = код шаблона категории. Используется для разрешения 'same_as_parent'.
    $tpls = [];

    // $subcats[area][parentCode] = [childCode, ...].
    // Обратный индекс «родитель → прямые дети». Строится за один проход,
    // затем присваивается каждой категории в ключ 'subcats' (см. шаг 3).
    // Нужен кастомным модулям, которые не вызывают cot_structure_children()
    // (например, market), а читают $structure[area][code]['subcats'] напрямую.
    $subcats = [];

    // Список аномалий (битые/сиротские строки). Логируем после цикла,
    // не прерывая работу — graceful degradation важнее, чем падение сайта.
    $anomalies = [];

    // Порядок сортировки критичен: родитель должен обрабатываться раньше детей,
    // иначе $path[$parentRpath] и $tpath[$parentRpath] ещё не будут известны.
    // В режиме COT_UPGRADE таблица может быть без structure_area — сортируем только по path.
    $orderBy = defined('COT_UPGRADE')
        ? 'structure_path ASC'
        : 'structure_area ASC, structure_path ASC';

    // Читаем все строки таблицы структуры. quoteTableName защищает от спецсимволов
    // в имени таблицы (например, при наличии префикса вида x92374_).
    $sql = Cot::$db->query(
        'SELECT * FROM ' . Cot::$db->quoteTableName($db_structure) . ' ORDER BY ' . $orderBy
    );

    // Список файлов плагинов, подписанных на хук 'structure'.
    // Хук вызывается ВНУТРИ цикла по строкам (как в стандартном Cotonti),
    // чтобы плагины видели $row текущей категории и могли её модифицировать.
    $extp = cot_getextplugins('structure');

    // Разделитель для построения tpath. Если в $cfg['separator'] нет HTML-тегов —
    // оборачиваем в пробелы (например, ' / '), иначе берём дефолтный ' / '.
    // Это защита от того, что кто-то положил в конфиг голый тег без пробелов.
    $separator = Cot::$cfg['separator'] === strip_tags(Cot::$cfg['separator'])
        ? ' ' . Cot::$cfg['separator'] . ' '
        : ' / ';

    // Итерируем по всем строкам таблицы структуры.
    foreach ($sql->fetchAll() as $row) {

        // Базовая защита от битых данных: пустой или нестроковый structure_code.
        // В схеме БД поле NOT NULL, но при прямом SQL-импорте или ручной правке
        // туда может попасть мусор. Пропускаем и логируем — не падаем.
        if (empty($row['structure_code']) || !is_string($row['structure_code'])) {
            $anomalies[] = "Skipped row with empty/invalid structure_code (id={$row['structure_id']})";
            continue;
        }

        // Короткие локальные псевдонимы — читаемость ниже и меньше обращений к массиву.
        $rpath = $row['structure_path']; // числовой путь из БД, например '100.100'
        $code  = $row['structure_code']; // код категории, например 'smartphones'
        $area  = $row['structure_area']; // область (расширение), например 'page'

        // Пустой structure_tpl означает «использовать structure_code как шаблон».
        // same_as_parent и конкретный код оставляем как есть — обработаем ниже.
        $row['structure_tpl'] = empty($row['structure_tpl'])
            ? $code
            : $row['structure_tpl'];

        // Ищем последнюю точку в числовом пути. Если её нет — это корень,
        // если есть — часть до неё является путём родителя.
        $lastDot = mb_strrpos($rpath, '.');

        // Ветвление: корень или ребёнок.
        if ($lastDot > 0) {
            // Ребёнок. Вычисляем путь родителя (всё до последней точки).
            $parentRpath = mb_substr($rpath, 0, $lastDot);

            // Проверяем, что родитель уже обработан (гарантируется ORDER BY structure_path ASC).
            // Если нет — значит, в БД есть «висячая» ветка (родитель удалён или путь битый).
            if (!isset($path[$parentRpath])) {

                // Аномалия: логируем, но не роняем запрос.
                $anomalies[] = "Missing parent '{$parentRpath}' for '{$area}.{$code}'";

                // Graceful degradation: считаем категорию корнем.
                // path = её собственный код, tpath = её заголовок.
                $path[$rpath]  = $code;
                $tpath[$rpath] = $row['structure_title'];

                // Родитель = сама себя (для разрешения same_as_parent ниже).
                $parentCode = $code;

                // ВАЖНО: $subcats НЕ пополняем — категория фактически стала корнем,
                // у неё нет реального родителя в дереве.
            } else {
                // Нормальный случай: собираем путь из кодов.
                $path[$rpath] = $path[$parentRpath] . '.' . $code;

                // Собираем путь из заголовков через разделитель.
                $tpath[$rpath] = $tpath[$parentRpath] . $separator . $row['structure_title'];

                // Определяем КОД родителя — последний сегмент его path.
                // Например, если path родителя = 'electronics', parentCode = 'electronics';
                // если path родителя = 'electronics.smartphones', parentCode = 'smartphones'.
                $pp = $path[$parentRpath];
                $pd = mb_strrpos($pp, '.');
                $parentCode = $pd > 0 ? mb_substr($pp, $pd + 1) : $pp;

                // Регистрируем ребёнка в обратном индексе subcats[area][parentCode][].
                // Это единственное место, где индекс пополняется для дочерних категорий.
                $subcats[$area][$parentCode][] = $code;
            }
        } else {
            // Корень. Нет точки — нет родителя. path = код, tpath = заголовок.
            $path[$rpath]  = $code;
            $tpath[$rpath] = $row['structure_title'];

            // Родитель = сама себя (для разрешения same_as_parent ниже).
            $parentCode = $code;

            // Корни в $subcats не попадают: они ничьи дети.
            // Для корней 'subcats' будет проставлен в шаге 3 как пустой массив.
        }

        // Разрешение 'same_as_parent': берём шаблон родителя, если он уже известен.
        // Fallback — собственный код категории (чтобы 'tpl' никогда не остался
        // буквально 'same_as_parent', как это бывает в стандартном Cotonti для корней).
        if ($row['structure_tpl'] === 'same_as_parent') {
            $row['structure_tpl'] = $tpls[$parentCode] ?? $code;
        }

        // Запоминаем итоговый шаблон категории. Нужно для её детей со 'same_as_parent'.
        $tpls[$code] = $row['structure_tpl'];

        // Формируем запись категории в runtime-массиве.
        // Единый формат совместим со стандартным Cotonti, за исключением 'subcats',
        // который присваивается на шаге 3 (после полного обхода).
        $structure[$area][$code] = [
            'path'   => $path[$rpath],           // путь из кодов
            'tpath'  => $tpath[$rpath],          // путь из заголовков
            'rpath'  => $rpath,                  // исходный числовой путь
            'id'     => (int) $row['structure_id'], // id записи, всегда int
            'tpl'    => $row['structure_tpl'],   // разрешённый код шаблона
            'title'  => $row['structure_title'], // заголовок для посетителя
            'desc'   => $row['structure_desc'],  // описание
            'icon'   => $row['structure_icon'],  // путь к иконке
            'locked' => (int) $row['structure_locked'], // флаг блокировки, int
            'count'  => (int) $row['structure_count'],  // счётчик материалов, int
        ];

        // Переносим значения доп. полей структуры в запись категории.
        // В runtime ключи БЕЗ префикса 'structure_' (например, 'vendor', а не 'structure_vendor').
        // Двойная проверка: !empty + is_array — защита от $cot_extrafields === null.
        if (!empty($cot_extrafields[$db_structure]) && is_array($cot_extrafields[$db_structure])) {
            foreach ($cot_extrafields[$db_structure] as $exfld) {
                $f = $exfld['field_name'];
                $structure[$area][$code][$f] = $row['structure_' . $f] ?? null;
            }
        }

        // Хук 'structure' — внутри цикла, как в стандартном Cotonti.
        // Плагины получают в scope: $row (текущая строка), $structure (по глобал),
        // $path, $tpath, $tpls, $subcats, $anomalies. Они могут модифицировать
        // текущую категорию или уже собранные — изменения сохранятся в итоговом массиве.
        foreach ($extp as $pl) {
            include $pl;
        }
    }

    // ===================== 3. Присвоение subcats каждой категории =====================
    // Проходим по всему дереву и проставляем 'subcats' (возможно пустой массив).
    // Это нужно, потому что кастомные модули (например, market) читают
    // $structure[$area][$code]['subcats'] напрямую и ожидают, что ключ существует.
    // Корни и листья получат пустой массив — array_filter(...) в шаблоне отработает корректно.
    foreach ($structure as $a => &$cats) {
        foreach ($cats as $c => &$item) {
            $item['subcats'] = $subcats[$a][$c] ?? [];
        }
        // Снимаем ссылку после внутреннего foreach — иначе следующая итерация
        // внешнего цикла будет писать в последний элемент предыдущей области.
        unset($item);
    }
    // Снимаем ссылку после внешнего foreach — аналогично, чтобы не «прилипнуть»
    // к последней области при возможных последующих операциях.
    unset($cats);

    // ============================ 4. Логирование аномалий ============================
    // Пишем в лог Cotonti каждую аномалию (битые/сиротские строки).
    // Делается после цикла, чтобы не мешать сборке; каждое сообщение — отдельно,
    // чтобы в логе было легко фильтровать по '[structure]'.
    foreach ($anomalies as $msg) {
        cot_log('[structure] ' . $msg, 'adm', 'structure', 'warn');
    }

    // ============================== 5. Сохранение в кеш ==============================
    // Кешируем только непустой результат, чтобы не «закэшировать» пустоту
    // при временной проблеме с БД (например, при миграции).
    // Ключ и TTL — из локальных переменных; при изменении формата массива
    // поднимите $cacheVersion, старый кеш будет проигнорирован.
    if (Cot::$cache && Cot::$cache->db && !empty($structure)) {
        Cot::$cache->db->store($cacheKey, $structure, 'system', $cacheTtl);
    }
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


/**
 * Возвращает абсолютный URL к файлу шаблона. {PHP|cot_tpl_url('forums.editpost')}
 * Использует cot_tplfile() для поиска файла и добавляет к нему базовый URL сайта.
 *
 * @param string|array $base  Имя шаблона (как в cot_tplfile)
 * @param string       $type  Тип расширения: 'plug', 'module' или 'core'
 * @param bool|null    $admin Использовать файл из админ-темы (по умолчанию автоопределение)
 * @return string|null Абсолютный URL к шаблону или null, если файл не найден
 *
 * @example
 * В любом .tpl-файле можно вставить:
 * <div>Шаблон: {PHP|cot_tpl_url('page.news')}</div>
 * Результат: https://example.com/themes/mytheme/modules/page/page.news.tpl
 */
function cot_tpl_url($base, $type = 'module', $admin = null)
{
    // Получаем путь к файлу через стандартную функцию
    $filePath = cot_tplfile($base, $type, $admin);
    if ($filePath === null) {
        return null;
    }

    // Определяем базовый URL сайта.
    // В Cotonti он хранится в глобальной переменной $sys['abs_url'] или в Cot::$sys['abs_url']
    global $sys;
    $absUrl = isset($sys['abs_url']) ? $sys['abs_url'] : (isset(Cot::$sys['abs_url']) ? Cot::$sys['abs_url'] : '');

    if (empty($absUrl)) {
        // Если базовый URL не определён, пытаемся вычислить из $_SERVER
        $absUrl = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
    }

    // Убираем лишние слэши и формируем итоговый URL.
    // Предполагаем, что $filePath относительный (начинается без ведущего слэша).
    // Если вдруг путь абсолютный (начинается с '/' или 'C:\'), то для простоты возвращаем его как есть
    // или можно дополнительно обработать через преобразование к относительному пути.
    if (preg_match('#^(/|[A-Za-z]:[\\\\/])#', $filePath)) {
        // Абсолютный путь – здесь можно реализовать преобразование, но обычно cot_tplfile возвращает относительный
        // Поэтому просто возвращаем null или сам путь (по ситуации)
        return null; // или return $filePath;
    }

    return rtrim($absUrl, '/') . '/' . ltrim($filePath, '/');
}

/**
 * Возвращает абсолютный URL текущего файла шаблона.
 *
 * Функция анализирует стек вызовов, находит объект XTemplate, который
 * в данный момент обрабатывает шаблон, извлекает из него путь к файлу
 * шаблона через Reflection API и добавляет к нему базовый URL сайта.
 * Это позволяет из любого .tpl-файла получить прямую ссылку на сам шаблон.
 *
 * @return string|null Абсолютный URL текущего шаблона или null, если не удалось определить.
 *
 * @example
 * В любом .tpl-файле можно вставить:
 * <div>Шаблон: {PHP|cot_debug_tpl_url()}</div>
 * Результат: https://example.com/themes/mytheme/modules/page/page.tpl
 */
function cot_debug_tpl_url()
{
    // Объявляем глобальную переменную $sys, чтобы получить доступ к настройкам системы.
    global $sys;

    // Получаем базовый URL сайта (например, https://example.com).
    // Функция rtrim() удаляет завершающий слэш, чтобы избежать дублирования
    // при последующей конкатенации с путём к файлу шаблона.
    $absUrl = isset($sys['abs_url']) ? rtrim($sys['abs_url'], '/') : '';

    /**
     * Получаем стек вызовов (backtrace).
     *
     * debug_backtrace() возвращает массив, каждый элемент которого описывает
     * один кадр стека вызовов (функцию/метод, файл, строку и т.д.).
     * Константа DEBUG_BACKTRACE_PROVIDE_OBJECT указывает PHP включить в каждый
     * кадр ключ 'object', содержащий экземпляр объекта, если вызов был сделан
     * внутри метода этого объекта. Это необходимо, чтобы в цикле ниже найти
     * объект XTemplate, который инициировал вызов нашей функции внутри шаблона.
     */
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);

    // Перебираем все кадры стека вызовов.
    foreach ($backtrace as $frame) {
        // Нас интересует только тот кадр, где есть объект,
        // и этот объект является экземпляром класса XTemplate.
        // Именно XTemplate отвечает за обработку шаблонов в Cotonti.
        if (isset($frame['object']) && $frame['object'] instanceof XTemplate) {
            // Инициализируем переменную для хранения пути к файлу шаблона.
            $tplFile = null;

            // Создаём объект ReflectionObject для доступа к защищённым и
            // приватным свойствам объекта XTemplate. Через Reflection можно
            // читать значения свойств, к которым нет прямого доступа извне.
            $ref = new ReflectionObject($frame['object']);

            // Перечень возможных имён свойства, в котором XTemplate хранит
            // путь к файлу шаблона. Разные версии Cotonti могут использовать
            // разные названия, поэтому проверяем несколько вариантов.
            $candidateProperties = ['filename', 'file', 'tpl_name', 'template'];

            // Проверяем каждое из предполагаемых имён.
            foreach ($candidateProperties as $propName) {
                // Если свойство с таким именем существует в классе объекта...
                if ($ref->hasProperty($propName)) {
                    // Получаем объект ReflectionProperty для этого свойства.
                    $prop = $ref->getProperty($propName);
                    // Начиная с PHP 8.1 метод setAccessible() больше не требуется
                    // для чтения приватных/защищённых свойств, поэтому он не вызывается.
                    // Получаем значение свойства (путь к файлу шаблона).
                    $tplFile = $prop->getValue($frame['object']);
                    // Прерываем цикл, так как нашли нужное свойство.
                    break;
                }
            }

            // Если ни одно из предполагаемых имён не было найдено,
            // используем запасной вариант: сканируем все свойства объекта
            // на наличие в имени подстрок 'file' или 'tpl' (вдруг название
            // отличается от ожидаемых).
            if ($tplFile === null) {
                // Перебираем все свойства, объявленные в классе объекта.
                foreach ($ref->getProperties() as $prop) {
                    // Получаем имя свойства.
                    $name = $prop->getName();
                    // Если имя содержит 'file' или 'tpl', считаем это искомым.
                    if (stripos($name, 'file') !== false || stripos($name, 'tpl') !== false) {
                        // Читаем значение свойства (путь к файлу).
                        $tplFile = $prop->getValue($frame['object']);
                        // Прерываем цикл.
                        break;
                    }
                }
            }

            // Если путь к файлу шаблона успешно получен...
            if (!empty($tplFile)) {
                // Формируем абсолютный URL: базовый URL + '/' + путь без ведущего слэша.
                // ltrim() убирает ведущий слэш у пути, чтобы не получилось двойного.
                return $absUrl . '/' . ltrim($tplFile, '/');
            }
        }
    }

    // Если не удалось найти объект XTemplate или извлечь путь, возвращаем null.
    return null;
}

