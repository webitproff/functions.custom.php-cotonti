# functions.custom.php-cotonti Cotonti Custom Functions Guide

This guide provides a detailed description of ten functions designed to determine the owner or author of content. Each function includes code examples and usage instructions.

## Table of Contents

- [Activating the Functions File](#activation-en)
- [Placing the Function Code](#code-placement-en)
- [Overview of `cot_customfuncs_*` Functions](#overview-en)
  - [1. `cot_customfuncs_getUserDataOwner()`](#func-getuserdataowner-en)
  - [2. `cot_customfuncs_isOwnerSuperAdmin()`](#func-isownersuperadmin-en)
  - [3. `cot_customfuncs_isOwnerAdmin()`](#func-isowneradmin-en)
  - [4. `cot_customfuncs_getOwnerGroupId()`](#func-getownergroupid-en)
  - [5. `cot_customfuncs_isOwner()`](#func-isowner-en)
  - [6. `cot_customfuncs_isTopicAuthor()`](#func-istopicauthor-en)
  - [7. `cot_customfuncs_isPageAuthor()`](#func-ispageauthor-en)
  - [8. `cot_customfuncs_getOwnerId()`](#func-getownerid-en)
  - [9. `cot_customfuncs_getOwnerName()`](#func-getownername-en)
  - [10. `cot_customfuncs_getPageOwnerId()`](#func-getpageownerid-en)
- [Function Summary Table](#summary-table-en)
- [Conclusion](#conclusion-en)

---

<a id="activation-en"></a>
## Activating the Functions File

Before using the functions, you need to ensure that the file `/system/functions.custom.php` is enabled in your site configuration.

Open `/datas/config.php`.

Find the line:

```php
$cfg['customfuncs'] = false;
```

And change it to:

```php
$cfg['customfuncs'] = true;
```

Now Cotonti will automatically load your custom functions from `/system/functions.custom.php`.

<a id="code-placement-en"></a>
## Placing the Function Code

Place all the function code in one file: **`/system/functions.custom.php`**.

---

<a id="overview-en"></a>
# Overview of `cot_customfuncs_*` Functions for Working with Page Owner and Content Author

This article provides a detailed overview of ten custom functions prefixed with `cot_customfuncs_`, designed to determine the page owner, check administrative rights, and verify authorship in Cotonti templates. Each function includes a description, examples of template calls, and expected results.

All functions use standard Cotonti mechanisms: `cot_import()`, `Cot::$db->query()`, `cot_auth_build()`, and global variables. Template call examples are given in Cotonti‑supported syntax: `{PHP|function_name()}` or `<!-- IF {PHP|...} -->`.

---

<a id="func-getuserdataowner-en"></a>
## 1. `cot_customfuncs_getUserDataOwner()`

### Purpose

Returns an array of data for the page owner user. The owner is determined by URL parameters `id` (numeric user ID) or `u` (username). If neither is provided, the current logged‑in user's ID is used.

**Important:** This function should **not be called directly in templates** because it returns an array that is not convenient to output without additional processing. Instead, use wrappers like `cot_customfuncs_getOwnerId()`, `cot_customfuncs_getOwnerName()`, etc.

### Logic

1. Gets `id` from the GET parameter `id` as an integer.
2. Gets `u` from the GET parameter `u` as a string.
3. If `u` is provided but `id` is not, it queries the users table to get `user_id` by `user_name`.
4. If neither `id` nor `u` is provided, but the user is logged in (`Cot::$usr['id'] > 0`), it uses their ID.
5. If the resulting `id > 0`, it returns a row from the users table (`SELECT * FROM users WHERE user_id = ?`) as an associative array.
6. If the user is not found, it returns `null`.

### PHP Call Example

```php
$owner = cot_customfuncs_getUserDataOwner();
if (!empty($owner)) {
    echo $owner['user_id'];    // 123
    echo $owner['user_name'];  // Alice
    echo $owner['user_email']; // alice@example.com
}
```

### Template Usage Example (via wrappers)

```html
<!-- IF {PHP|cot_customfuncs_getOwnerId()} > 0 -->
    The profile belongs to a registered user.
<!-- ENDIF -->
```

### Return Value

- **Array** – user data if found. Contains all fields from the `cot_users` table, including `user_id`, `user_name`, `user_email`, `user_maingrp`, etc.
- **`null`** – if the user is not found, or parameters are missing and the current user is not logged in.

### Example Return

When opening a page with URL `?id=42`, and a user with `user_id=42` exists:

```php
[
    'user_id' => 42,
    'user_name' => 'john_doe',
    'user_email' => 'john@example.com',
    'user_maingrp' => 4,
    ...
]
```

---

<a id="func-isownersuperadmin-en"></a>
## 2. `cot_customfuncs_isOwnerSuperAdmin()`

### Purpose

Checks whether the **page owner** is a super administrator (group `COT_GROUP_SUPERADMINS`, usually ID 5). This function does not check the current user, but specifically the owner determined via `cot_customfuncs_getUserDataOwner()`.

### Logic

1. Calls `cot_customfuncs_getUserDataOwner()`.
2. If data is obtained and the key `user_maingrp` exists, it compares its value with the constant `COT_GROUP_SUPERADMINS` (value 5) after casting to integer.
3. Returns `true` or `false`.

### Template Usage Example

```html
<!-- IF {PHP|cot_customfuncs_isOwnerSuperAdmin()} -->
    <div class="alert alert-danger">The page owner is a super admin.</div>
<!-- ELSE -->
    <div class="alert alert-info">The page owner is not a super admin.</div>
<!-- ENDIF -->
```

### Return Value

- **`true`** – if the owner is found and their main group equals `COT_GROUP_SUPERADMINS`.
- **`false`** – in all other cases.

### Example Result

When `owner.user_maingrp = 5`:

```
The page owner is a super admin.
```

When `owner.user_maingrp = 4`:

```
The page owner is not a super admin.
```

---

<a id="func-isowneradmin-en"></a>
## 3. `cot_customfuncs_isOwnerAdmin()`

### Purpose

Checks whether the **page owner** has administrator rights in the `admin` area. Unlike `isOwnerSuperAdmin()`, it considers not only the main group `COT_GROUP_SUPERADMINS` but also **secondary groups** that may grant administrative privileges (e.g., the "Administrators" group with appropriate rights in the `cot_auth` table).

### Logic

1. Gets owner data via `cot_customfuncs_getUserDataOwner()`.
2. If no `user_id`, returns `false`.
3. Extracts `user_id` and `user_maingrp`.
4. Calls `cot_auth_build($ownerId, $maingrp)` – this builds a rights matrix for the specified user based on their main and secondary groups.
5. Checks for the presence of `$rights['admin']['a']` (admin rights in the `admin` area).
6. Gets the bitmask of rights and checks if bit `A` (value 128) is set.
7. Returns `true` if the bit is set, otherwise `false`.

### Template Usage Example

```html
<!-- IF {PHP|cot_customfuncs_isOwnerAdmin()} -->
    <span class="badge bg-success">Owner is an administrator</span>
<!-- ELSE -->
    <span class="badge bg-secondary">Owner is a regular user</span>
<!-- ENDIF -->
```

### Return Value

- **`true`** – if the owner has administrator rights (super admin or belongs to a group with the admin flag).
- **`false`** – if the owner is not an administrator or not found.

### Example Result

If the owner is in a secondary group "Administrators" (with `A` rights in the `admin` area), the function returns `true`, and the template displays:

```
Owner is an administrator
```

If the owner is a regular user:

```
Owner is a regular user
```

---

<a id="func-getownergroupid-en"></a>
## 4. `cot_customfuncs_getOwnerGroupId()`

### Purpose

Returns the numeric ID of the main group (`user_maingrp`) of the page owner. Allows checking the owner's group membership without directly accessing the data array.

### Logic

1. Calls `cot_customfuncs_getUserDataOwner()`.
2. If the array contains `user_maingrp`, returns it as `int`.
3. If the owner is not found or the key is empty, returns `0`.

### Template Usage Example

```html
<!-- IF {PHP|cot_customfuncs_getOwnerGroupId()} == 5 -->
    Owner is a super admin
<!-- ELSE -->
    <!-- IF {PHP|cot_customfuncs_getOwnerGroupId()} == 6 -->
        Owner is a moderator
    <!-- ELSE -->
        Owner belongs to another group
    <!-- ENDIF -->
<!-- ENDIF -->
```

### Return Value

- **Integer** – ID of the owner's main group (e.g., 4, 5, 6).
- **`0`** – if the owner is not found.

### Example Result

If the owner has `user_maingrp = 5`:

```
Owner is a super admin
```

---

<a id="func-isowner-en"></a>
## 5. `cot_customfuncs_isOwner()`

### Purpose

Checks whether the **currently logged‑in user** is the owner of the page. Compares the current user's ID with the owner ID obtained via `cot_customfuncs_getUserDataOwner()`.

### Logic

1. Gets owner data.
2. If data is found, the current user is logged in (`Cot::$usr['id'] > 0`), and their ID matches the owner's `user_id`, returns `true`.
3. Otherwise `false`.

### Template Usage Example

```html
<!-- IF {PHP|cot_customfuncs_isOwner()} -->
    <a href="{PHP|cot_url('users', 'm=profile')}">Edit profile</a>
<!-- ENDIF -->
```

### Return Value

- **`true`** – if the current user is the owner of the page being viewed.
- **`false`** – if not the owner or not logged in.

### Example Result

If the current user has `user_id = 42` and the page owner also has `user_id = 42`, the "Edit profile" link will be shown.

---

<a id="func-istopicauthor-en"></a>
## 6. `cot_customfuncs_isTopicAuthor()`

### Purpose

Checks whether the current user is the author of a forum topic. The topic ID is taken from the URL parameter `q`. The function compares `ft_firstposterid` (ID of the first post's author) with the current user's ID.

### Logic

1. Gets `topicId` from GET parameter `q` as an integer.
2. If the user is not logged in or `topicId` is empty, returns `false`.
3. Queries the `cot_forum_topics` table to get `ft_firstposterid`.
4. If `posterId > 0` and matches the current user's ID, returns `true`, otherwise `false`.

### Template Usage Example

```html
<!-- IF {PHP|cot_customfuncs_isTopicAuthor()} -->
    <button>Delete topic</button>
<!-- ENDIF -->
```

### Return Value

- **`true`** – if the current user is the topic author.
- **`false`** – if not the author, not logged in, or the `q` parameter is missing.

### Example Result

With URL `?q=15`, where topic `ft_id=15` was created by user ID 42, and the current user has ID 42, the "Delete topic" button will appear.

---

<a id="func-ispageauthor-en"></a>
## 7. `cot_customfuncs_isPageAuthor()`

### Purpose

Checks whether the current user is the author of a page (article). The page ID is taken from the URL parameter `id`. Compares `page_ownerid` with the current user's ID.

### Logic

1. Gets `pageId` from GET parameter `id` as an integer.
2. If the user is not logged in or `pageId` is empty, returns `false`.
3. Queries the `cot_pages` table to get `page_ownerid`.
4. If `ownerId > 0` and matches the current user's ID, returns `true`, otherwise `false`.

### Template Usage Example

```html
<!-- IF {PHP|cot_customfuncs_isPageAuthor()} -->
    <div>You are the author of this article.</div>
<!-- ELSE -->
    <div>You are not the author.</div>
<!-- ENDIF -->
```

### Return Value

- **`true`** – if the current user is the article author.
- **`false`** – if not the author or the `id` parameter is missing.

### Example Result

With URL `?id=99`, where page `page_id=99` belongs to user ID 42, and the current user has ID 42, the output will be "You are the author of this article."

---

<a id="func-getownerid-en"></a>
## 8. `cot_customfuncs_getOwnerId()`

### Purpose

Returns the numeric ID of the page owner. This is a wrapper around `cot_customfuncs_getUserDataOwner()` extracting only `user_id`.

### Logic

1. Calls `cot_customfuncs_getUserDataOwner()`.
2. If the array contains `user_id`, returns it as `int`, otherwise `0`.

### Template Usage Example

```html
Owner ID: {PHP|cot_customfuncs_getOwnerId()}
```

### Return Value

- **Integer** – owner ID (e.g., 42).
- **`0`** – if the owner is not found.

### Example Result

If the owner is found:

```
Owner ID: 42
```

---

<a id="func-getownername-en"></a>
## 9. `cot_customfuncs_getOwnerName()`

### Purpose

Returns the owner's username escaped for safe HTML output. This is a wrapper around `cot_customfuncs_getUserDataOwner()` extracting `user_name` and applying `htmlspecialchars()`.

### Logic

1. Calls `cot_customfuncs_getUserDataOwner()`.
2. If the array contains `user_name`, returns it after `htmlspecialchars()`.
3. If the owner is not found or the name is empty, returns an empty string.

### Template Usage Example

```html
Owner name: {PHP|cot_customfuncs_getOwnerName()}
```

### Return Value

- **String** – owner's name (e.g., `"Alice"`), already escaped.
- **Empty string** – if the name is not found.

### Example Result

When a name exists:

```
Owner name: Alice
```

---

<a id="func-getpageownerid-en"></a>
## 10. `cot_customfuncs_getPageOwnerId()`

### Purpose

Returns the numeric ID of the page owner (article), based on the current URL. Unlike `cot_customfuncs_getOwnerId()`, this function queries the `cot_pages` table directly instead of the users table.

### Logic

1. Gets `pageId` from GET parameter `id` as an integer.
2. Executes query: `SELECT page_ownerid FROM cot_pages WHERE page_id = ?`.
3. If a value is returned, returns it as `int`, otherwise `0`.

### Template Usage Example

```html
Article author ID: {PHP|cot_customfuncs_getPageOwnerId()}
```

### Return Value

- **Integer** – article owner ID.
- **`0`** – if the article is not found or `ownerid` is empty.

### Example Result

With URL `?id=99`, where `page_ownerid=42`:

```
Article author ID: 42
```

---

<a id="summary-table-en"></a>
## Function Summary Table

| Function | Purpose | Returns |
|----------|---------|---------|
| `cot_customfuncs_getUserDataOwner()` | Full owner data array | `array` or `null` |
| `cot_customfuncs_isOwnerSuperAdmin()` | Is owner a super admin? | `bool` |
| `cot_customfuncs_isOwnerAdmin()` | Is owner an administrator (including secondary groups)? | `bool` |
| `cot_customfuncs_getOwnerGroupId()` | Owner's main group ID | `int` |
| `cot_customfuncs_isOwner()` | Is current user the owner? | `bool` |
| `cot_customfuncs_isTopicAuthor()` | Is current user the topic author? | `bool` |
| `cot_customfuncs_isPageAuthor()` | Is current user the article author? | `bool` |
| `cot_customfuncs_getOwnerId()` | Owner ID | `int` |
| `cot_customfuncs_getOwnerName()` | Owner name | `string` |
| `cot_customfuncs_getPageOwnerId()` | Article owner ID (direct query) | `int` |

---

<a id="conclusion-en"></a>
## Conclusion

The set of `cot_customfuncs_*` functions provides a convenient interface for working with the page owner and verifying authorship in Cotonti templates. Use them according to your specific task:

- To check the **current user's** ownership of content, use `cot_customfuncs_isOwner()`, `cot_customfuncs_isPageAuthor()`, or `cot_customfuncs_isTopicAuthor()`.
- To obtain information about the **page owner**, use `cot_customfuncs_getOwnerId()`, `cot_customfuncs_getOwnerName()`, or `cot_customfuncs_getOwnerGroupId()`.
- To check the owner's **administrative privileges**, use `cot_customfuncs_isOwnerAdmin()` (considers secondary groups) or `cot_customfuncs_isOwnerSuperAdmin()` (super admin only).

All functions are built on standard Cotonti mechanisms and are safe to use in templates.


___
> RU
___


# Руководство по пользовательским функциям Cotonti

Это руководство содержит подробное описание четырех функций, предназначенных для определения владельца или автора контента. Каждая функция снабжена примерами кода и инструкциями по использованию.

## Оглавление

- [Активация файла с функциями](#activation)
- [Размещение кода функций](#code-placement)
- [Обзор функций `cot_customfuncs_*`](#overview)
  - [1. `cot_customfuncs_getUserDataOwner()`](#func-getuserdataowner)
  - [2. `cot_customfuncs_isOwnerSuperAdmin()`](#func-isownersuperadmin)
  - [3. `cot_customfuncs_isOwnerAdmin()`](#func-isowneradmin)
  - [4. `cot_customfuncs_getOwnerGroupId()`](#func-getownergroupid)
  - [5. `cot_customfuncs_isOwner()`](#func-isowner)
  - [6. `cot_customfuncs_isTopicAuthor()`](#func-istopicauthor)
  - [7. `cot_customfuncs_isPageAuthor()`](#func-ispageauthor)
  - [8. `cot_customfuncs_getOwnerId()`](#func-getownerid)
  - [9. `cot_customfuncs_getOwnerName()`](#func-getownername)
  - [10. `cot_customfuncs_getPageOwnerId()`](#func-getpageownerid)
- [Сводная таблица функций](#summary-table)
- [Заключение](#conclusion)

---

<a id="activation"></a>
## Активация файла с функциями

Прежде чем использовать функции, вам необходимо убедиться, что файл `/system/functions.custom.php` включен в конфигурации сайта.

Откройте файл `/datas/config.php`.

Найдите строку:

```php
$cfg['customfuncs'] = false;
```

И измените её на:

```php
$cfg['customfuncs'] = true;
```

Теперь Cotonti будет автоматически загружать ваши пользовательские функции из `/system/functions.custom.php`.

<a id="code-placement"></a>
## Размещение кода функций

Весь код функций следует разместить в одном файле: **`/system/functions.custom.php`**.

---

<a id="overview"></a>
# Обзор функций `cot_customfuncs_*` для работы с владельцем страницы и автором контента

В этой статье подробно рассматриваются десять пользовательских функций с префиксом `cot_customfuncs_`, предназначенных для определения владельца страницы, проверки прав администратора и авторства в шаблонах Cotonti. Каждая функция снабжена описанием, примерами вызова в шаблонах и ожидаемым результатом.

Все функции используют штатные механизмы Cotonti: `cot_import()`, `Cot::$db->query()`, `cot_auth_build()` и глобальные переменные. Примеры вызова в шаблонах приведены в синтаксисе, поддерживаемом Cotonti: `{PHP|имя_функции()}` или `<!-- IF {PHP|...} -->`.

---

<a id="func-getuserdataowner"></a>
## 1. `cot_customfuncs_getUserDataOwner()`

### Назначение

Возвращает массив данных пользователя-владельца страницы. Владелец определяется по URL-параметрам `id` (числовой ID пользователя) или `u` (имя пользователя). Если ни один из них не передан, используется ID текущего авторизованного пользователя.

**Важно:** эту функцию **не следует вызывать в шаблонах напрямую**, так как она возвращает массив, который неудобно выводить без дополнительной обработки. Вместо неё используйте обёртки: `cot_customfuncs_getOwnerId()`, `cot_customfuncs_getOwnerName()` и т.п.

### Логика работы

1. Получает `id` из GET-параметра `id` как целое число.
2. Получает `u` из GET-параметра `u` как строку.
3. Если передан `u`, но не `id` — выполняет запрос к таблице пользователей для получения `user_id` по `user_name`.
4. Если не переданы ни `id`, ни `u`, но пользователь авторизован (`Cot::$usr['id'] > 0`) — использует его ID.
5. Если в итоге `id > 0`, возвращает строку из таблицы пользователей (`SELECT * FROM users WHERE user_id = ?`) как ассоциативный массив.
6. Если пользователь не найден — возвращает `null`.

### Пример вызова в PHP

```php
$owner = cot_customfuncs_getUserDataOwner();
if (!empty($owner)) {
    echo $owner['user_id'];    // 123
    echo $owner['user_name'];  // Alice
    echo $owner['user_email']; // alice@example.com
}
```

### Пример использования в шаблоне (через обёртки)

```html
<!-- IF {PHP|cot_customfuncs_getOwnerId()} > 0 -->
    Профиль принадлежит зарегистрированному пользователю.
<!-- ENDIF -->
```

### Возвращаемое значение

- **Массив** `array` – данные пользователя, если он найден. Содержит все поля таблицы `cot_users`, включая `user_id`, `user_name`, `user_email`, `user_maingrp` и т.д.
- **`null`** – если пользователь не найден или параметры отсутствуют и текущий пользователь не авторизован.

### Пример возвращаемого результата

При открытии страницы с URL `?id=42`, где пользователь с `user_id=42` существует:

```php
[
    'user_id' => 42,
    'user_name' => 'john_doe',
    'user_email' => 'john@example.com',
    'user_maingrp' => 4,
    ...
]
```

---

<a id="func-isownersuperadmin"></a>
## 2. `cot_customfuncs_isOwnerSuperAdmin()`

### Назначение

Проверяет, является ли **владелец страницы** суперадминистратором (группа `COT_GROUP_SUPERADMINS`, обычно ID 5). Эта функция не проверяет текущего пользователя, а именно владельца, определённого через `cot_customfuncs_getUserDataOwner()`.

### Логика работы

1. Вызывает `cot_customfuncs_getUserDataOwner()`.
2. Если данные получены и ключ `user_maingrp` существует, сравнивает его значение с константой `COT_GROUP_SUPERADMINS` (значение 5) с приведением к целому числу.
3. Возвращает `true` или `false`.

### Пример использования в шаблоне

```html
<!-- IF {PHP|cot_customfuncs_isOwnerSuperAdmin()} -->
    <div class="alert alert-danger">Владелец страницы — суперадмин</div>
<!-- ELSE -->
    <div class="alert alert-info">Владелец страницы не суперадмин</div>
<!-- ENDIF -->
```

### Возвращаемое значение

- **`true`** – если владелец найден и его основная группа равна `COT_GROUP_SUPERADMINS`.
- **`false`** – во всех остальных случаях.

### Пример результата

При `owner.user_maingrp = 5`:

```
Владелец страницы — суперадмин
```

При `owner.user_maingrp = 4`:

```
Владелец страницы не суперадмин
```

---

<a id="func-isowneradmin"></a>
## 3. `cot_customfuncs_isOwnerAdmin()`

### Назначение

Проверяет, имеет ли **владелец страницы** права администратора в области `admin`. В отличие от `isOwnerSuperAdmin()`, учитывает не только основную группу `COT_GROUP_SUPERADMINS`, но и **дополнительные группы**, которые могут давать административные права (например, группа «Администраторы» с соответствующими правами в таблице `cot_auth`).

### Логика работы

1. Получает данные владельца через `cot_customfuncs_getUserDataOwner()`.
2. Если нет `user_id` — возвращает `false`.
3. Извлекает `user_id` и `user_maingrp`.
4. Вызывает `cot_auth_build($ownerId, $maingrp)` — эта функция строит матрицу прав для указанного пользователя на основе его основной и дополнительных групп.
5. Проверяет наличие элемента `$rights['admin']['a']` (права администратора в области `admin`).
6. Получает битовую маску прав и проверяет, установлен ли бит `A` (значение 128).
7. Возвращает `true`, если бит установлен, иначе `false`.

### Пример использования в шаблоне

```html
<!-- IF {PHP|cot_customfuncs_isOwnerAdmin()} -->
    <span class="badge bg-success">Владелец — администратор</span>
<!-- ELSE -->
    <span class="badge bg-secondary">Владелец — обычный пользователь</span>
<!-- ENDIF -->
```

### Возвращаемое значение

- **`true`** – если владелец имеет права администратора (суперадмин или состоит в группе с флагом администратора).
- **`false`** – если владелец не администратор или не найден.

### Пример результата

Если владелец состоит в дополнительной группе «Администраторы» (с правами `A` в области `admin`), функция вернёт `true`, и шаблон выведет:

```
Владелец — администратор
```

Если владелец — обычный пользователь, результат:

```
Владелец — обычный пользователь
```

---

<a id="func-getownergroupid"></a>
## 4. `cot_customfuncs_getOwnerGroupId()`

### Назначение

Возвращает числовой идентификатор основной группы (`user_maingrp`) владельца страницы. Позволяет проверять принадлежность владельца к конкретной группе без необходимости обращаться к массиву данных напрямую.

### Логика работы

1. Вызывает `cot_customfuncs_getUserDataOwner()`.
2. Если в массиве есть `user_maingrp`, возвращает его как `int`.
3. Если владелец не найден или ключ пуст — возвращает `0`.

### Пример использования в шаблоне

```html
<!-- IF {PHP|cot_customfuncs_getOwnerGroupId()} == 5 -->
    Владелец — суперадмин
<!-- ELSE -->
    <!-- IF {PHP|cot_customfuncs_getOwnerGroupId()} == 6 -->
        Владелец — модератор
    <!-- ELSE -->
        Владелец — другая группа
    <!-- ENDIF -->
<!-- ENDIF -->
```

### Возвращаемое значение

- **Целое число** – ID основной группы владельца (например, 4, 5, 6).
- **`0`** – если владелец не найден.

### Пример результата

Если владелец имеет `user_maingrp = 5`, то:

```
Владелец — суперадмин
```

---

<a id="func-isowner"></a>
## 5. `cot_customfuncs_isOwner()`

### Назначение

Проверяет, является ли **текущий авторизованный пользователь** владельцем страницы. Сравнивает ID текущего пользователя с ID владельца, полученного через `cot_customfuncs_getUserDataOwner()`.

### Логика работы

1. Получает данные владельца.
2. Если данные найдены, текущий пользователь авторизован (`Cot::$usr['id'] > 0`) и его ID совпадает с `user_id` владельца, возвращает `true`.
3. Иначе `false`.

### Пример использования в шаблоне

```html
<!-- IF {PHP|cot_customfuncs_isOwner()} -->
    <a href="{PHP|cot_url('users', 'm=profile')}">Редактировать профиль</a>
<!-- ENDIF -->
```

### Возвращаемое значение

- **`true`** – если текущий пользователь является владельцем просматриваемой страницы.
- **`false`** – если не владелец или не авторизован.

### Пример результата

Если текущий пользователь имеет `user_id = 42`, а владелец страницы тоже `user_id = 42`, то ссылка «Редактировать профиль» будет показана.

---

<a id="func-istopicauthor"></a>
## 6. `cot_customfuncs_isTopicAuthor()`

### Назначение

Проверяет, является ли текущий пользователь автором топика форума. ID топика берётся из URL-параметра `q`. Функция сравнивает `ft_firstposterid` (ID автора первого сообщения) с ID текущего пользователя.

### Логика работы

1. Получает `topicId` из GET-параметра `q` как целое число.
2. Если пользователь не авторизован или `topicId` пуст — возвращает `false`.
3. Выполняет запрос к таблице `cot_forum_topics` для получения `ft_firstposterid`.
4. Если `posterId > 0` и совпадает с ID текущего пользователя — возвращает `true`, иначе `false`.

### Пример использования в шаблоне

```html
<!-- IF {PHP|cot_customfuncs_isTopicAuthor()} -->
    <button>Удалить тему</button>
<!-- ENDIF -->
```

### Возвращаемое значение

- **`true`** – если текущий пользователь автор топика.
- **`false`** – если не автор, не авторизован или параметр `q` отсутствует.

### Пример результата

При URL `?q=15`, где топик `ft_id=15` создан пользователем с ID 42, и текущий пользователь имеет ID 42, кнопка «Удалить тему» будет показана.

---

<a id="func-ispageauthor"></a>
## 7. `cot_customfuncs_isPageAuthor()`

### Назначение

Проверяет, является ли текущий пользователь автором статьи (страницы). ID статьи берётся из URL-параметра `id`. Сравнивает `page_ownerid` с ID текущего пользователя.

### Логика работы

1. Получает `pageId` из GET-параметра `id` как целое число.
2. Если пользователь не авторизован или `pageId` пуст — возвращает `false`.
3. Выполняет запрос к таблице `cot_pages` для получения `page_ownerid`.
4. Если `ownerId > 0` и совпадает с ID текущего пользователя — возвращает `true`, иначе `false`.

### Пример использования в шаблоне

```html
<!-- IF {PHP|cot_customfuncs_isPageAuthor()} -->
    <div>Вы автор этой статьи.</div>
<!-- ELSE -->
    <div>Вы не являетесь автором.</div>
<!-- ENDIF -->
```

### Возвращаемое значение

- **`true`** – если текущий пользователь автор статьи.
- **`false`** – если не автор или параметр `id` отсутствует.

### Пример результата

При URL `?id=99`, где страница `page_id=99` принадлежит пользователю с ID 42, и текущий пользователь ID 42, выведется «Вы автор этой статьи».

---

<a id="func-getownerid"></a>
## 8. `cot_customfuncs_getOwnerId()`

### Назначение

Возвращает числовой ID пользователя-владельца страницы. Это обёртка над `cot_customfuncs_getUserDataOwner()`, извлекающая только `user_id`.

### Логика работы

1. Вызывает `cot_customfuncs_getUserDataOwner()`.
2. Если в массиве есть `user_id`, возвращает его как `int`, иначе `0`.

### Пример использования в шаблоне

```html
ID владельца: {PHP|cot_customfuncs_getOwnerId()}
```

### Возвращаемое значение

- **Целое число** – ID владельца (например, 42).
- **`0`** – если владелец не найден.

### Пример результата

Если владелец найден:

```
ID владельца: 42
```

---

<a id="func-getownername"></a>
## 9. `cot_customfuncs_getOwnerName()`

### Назначение

Возвращает имя владельца страницы в экранированном виде (для безопасного вывода в HTML). Это обёртка над `cot_customfuncs_getUserDataOwner()`, извлекающая `user_name` и применяющая `htmlspecialchars()`.

### Логика работы

1. Вызывает `cot_customfuncs_getUserDataOwner()`.
2. Если в массиве есть `user_name`, возвращает его после `htmlspecialchars()`.
3. Если владелец не найден или имя пустое — возвращает пустую строку.

### Пример использования в шаблоне

```html
Имя владельца: {PHP|cot_customfuncs_getOwnerName()}
```

### Возвращаемое значение

- **Строка** – имя владельца (например, `"Alice"`), уже экранированное.
- **Пустая строка** – если имя не найдено.

### Пример результата

При наличии имени:

```
Имя владельца: Alice
```

---

<a id="func-getpageownerid"></a>
## 10. `cot_customfuncs_getPageOwnerId()`

### Назначение

Возвращает числовой ID владельца статьи (страницы), основываясь на текущем URL. В отличие от `cot_customfuncs_getOwnerId()`, эта функция выполняет прямой запрос к таблице `cot_pages`, а не к пользователям.

### Логика работы

1. Получает `pageId` из GET-параметра `id` как целое число.
2. Выполняет запрос: `SELECT page_ownerid FROM cot_pages WHERE page_id = ?`.
3. Если значение получено, возвращает его как `int`, иначе `0`.

### Пример использования в шаблоне

```html
ID автора статьи: {PHP|cot_customfuncs_getPageOwnerId()}
```

### Возвращаемое значение

- **Целое число** – ID владельца статьи.
- **`0`** – если статья не найдена или `ownerid` пуст.

### Пример результата

При URL `?id=99`, где `page_ownerid=42`:

```
ID автора статьи: 42
```

---

<a id="summary-table"></a>
## Сводная таблица функций

| Функция | Назначение | Возвращает |
|---------|------------|------------|
| `cot_customfuncs_getUserDataOwner()` | Полный массив данных владельца | `array` или `null` |
| `cot_customfuncs_isOwnerSuperAdmin()` | Владелец — суперадмин? | `bool` |
| `cot_customfuncs_isOwnerAdmin()` | Владелец — администратор (включая доп. группы)? | `bool` |
| `cot_customfuncs_getOwnerGroupId()` | Основная группа владельца | `int` |
| `cot_customfuncs_isOwner()` | Текущий пользователь — владелец? | `bool` |
| `cot_customfuncs_isTopicAuthor()` | Текущий пользователь — автор топика? | `bool` |
| `cot_customfuncs_isPageAuthor()` | Текущий пользователь — автор статьи? | `bool` |
| `cot_customfuncs_getOwnerId()` | ID владельца страницы | `int` |
| `cot_customfuncs_getOwnerName()` | Имя владельца страницы | `string` |
| `cot_customfuncs_getPageOwnerId()` | ID владельца статьи (прямой запрос) | `int` |

---

<a id="conclusion"></a>
## Заключение

Набор функций `cot_customfuncs_*` предоставляет удобный интерфейс для работы с владельцем страницы и проверки авторства в шаблонах Cotonti. Используйте их в соответствии с конкретной задачей:

- Для проверки прав **текущего пользователя** на владение контентом используйте `cot_customfuncs_isOwner()`, `cot_customfuncs_isPageAuthor()` или `cot_customfuncs_isTopicAuthor()`.
- Для получения информации о **владельце страницы** применяйте `cot_customfuncs_getOwnerId()`, `cot_customfuncs_getOwnerName()`, `cot_customfuncs_getOwnerGroupId()`.
- Для проверки **административных прав владельца** используйте `cot_customfuncs_isOwnerAdmin()` (учитывает дополнительные группы) или `cot_customfuncs_isOwnerSuperAdmin()` (только суперадмин).

Все функции строятся на штатных механизмах Cotonti и безопасны для использования в шаблонах.
