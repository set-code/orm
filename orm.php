<?php
/**
 * Class ORM
 *
 * Простая реализация ORM (Object-Relational Mapping) для работы с базами данных на PHP.
 * Позволяет выполнять CRUD (Create, Read, Update, Delete) операции с использованием PDO.
 */
class ORM
{
    protected static $pdo; // Экземпляр PDO для работы с базой данных
    protected static $table; // Имя таблицы, с которой работает ORM


    /**
     * ORM constructor.
     *
     * @param string $table Имя таблицы, с которой будет работать ORM.
     */
    public function __construct($table)
    {
        self::$table = $table;
    }


    /**
     * Устанавливает подключение к базе данных с использованием предоставленных параметров.
     *
     * @param string $dsn DSN (Data Source Name) строка для подключения к базе данных (например, "mysql:host=localhost;dbname=test").
     * @param string $username Имя пользователя для аутентификации в базе данных.
     * @param string $password Пароль пользователя для аутентификации в базе данных.
     * @throws Exception Если не удалось подключиться к базе данных, выбрасывается исключение с сообщением об ошибке.
     *
     * Пример использования:
     * ORM::setup('mysql:host=localhost;dbname=testdb', 'user', 'password');
     */
    public static function setup($dsn, $username, $password)
    {
        try {
            self::$pdo = new PDO($dsn, $username, $password);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к базе данных: " . $e->getMessage());
        }
    }


    /**
     * Проверяет, существует ли таблица в базе данных.
     *
     * @param string $table Имя таблицы, наличие которой нужно проверить.
     * @return bool Возвращает `true`, если таблица существует, иначе `false`.
     *
     * Пример использования:
     * $orm = new ORM('users');
     * if ($orm->tableExists('users')) {
     *     echo "Таблица существует";
     * } else {
     *     echo "Таблица не существует";
     * }
     */
    protected function tableExists($table)
    {
        $result = self::$pdo->query("SHOW TABLES LIKE '$table'")->fetch();
        return $result !== false;
    }


    /**
     * Создаёт новую таблицу в базе данных на основе предоставленных данных.
     *
     * @param string $table Имя таблицы для создания.
     * @param array $data Ассоциативный массив данных, где ключи — это имена столбцов, а значения — примеры значений, определяющие типы данных.
     * @return void
     *
     * Пример использования:
     * $orm = new ORM('users');
     * $userData = ['name' => 'Jane Doe', 'email' => 'jane.doe@example.com', 'age' => 29];
     * $orm->createTable('users', $userData); // Создаёт таблицу `users` с колонками `name`, `email` и `age`
     */
    protected function createTable($table, $data)
    {
        $columns = [];
        foreach ($data as $column => $value) {
            $type = $this->determineColumnType($value);
            $columns[] = "$column $type";
        }

        $columnsSql = implode(", ", $columns);
        $sql = "CREATE TABLE $table (id INT AUTO_INCREMENT PRIMARY KEY, $columnsSql)";
        self::$pdo->exec($sql);
    }


    /**
     * Добавляет отсутствующие колонки в существующую таблицу.
     *
     * @param string $table Имя таблицы, в которую нужно добавить колонки.
     * @param array $data Данные, используемые для определения типов колонок.
     */
    protected function addMissingColumns($table, $data)
    {
        foreach ($data as $column => $value) {
            $result = self::$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
            if (!$result) {
                $type = $this->determineColumnType($value);
                self::$pdo->exec("ALTER TABLE `$table` ADD `$column` $type");
            } else {
                // Изменяем тип колонки при необходимости
                $this->changeColumnTypeIfNeeded($table, $column, $value);
            }
        }
    }


    /**
     * Изменяет тип колонки, если это необходимо.
     *
     * @param string $table Имя таблицы.
     * @param string $column Имя колонки для изменения типа.
     * @param mixed $value Значение, используемое для определения нового типа колонки.
     */
    protected function changeColumnTypeIfNeeded($table, $column, $value)
    {
        $currentType = $this->getColumnType($table, $column);
        $newType = $this->determineColumnType($value);

        // Проверяем, различаются ли типы, и изменяем при необходимости
        if ($currentType !== $newType) {
            self::$pdo->exec("ALTER TABLE `$table` MODIFY `$column` $newType");
        }
    }


    /**
     * Получает тип колонки из таблицы.
     *
     * @param string $table Имя таблицы.
     * @param string $column Имя колонки.
     * @return string|null Тип колонки или null, если колонка не найдена.
     */
    protected function getColumnType($table, $column)
    {
        $result = self::$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch();
        return $result ? $result['Type'] : null;
    }


    /**
     * Определяет тип колонки на основе значения.
     *
     * @param mixed $value Значение, для которого необходимо определить тип.
     * @return string Тип колонки.
     */
    protected function determineColumnType($value)
    {
        if ($value instanceof DateTime) {
            return 'DATETIME';
        } elseif (is_int($value)) {
            return 'INT';
        } elseif (is_float($value) || is_double($value)) {
            return 'FLOAT';
        } elseif (is_bool($value)) {
            return 'TINYINT(1)';
        } elseif (is_string($value)) {
            return strlen($value) > 255 ? "TEXT" : "VARCHAR(255)";
        } elseif (is_array($value)) {
            return 'TEXT';
        }

        return 'TEXT';
    }



    /**
     * Обрабатывает данные перед записью в базу данных.
     *
     * @param array $data Данные для обработки.
     * @return array Обработанные данные.
     */
    protected function preprocessData($data)
    {
        $processedData = [];
        foreach ($data as $key => $value) {
            if ($value instanceof DateTime) {
                $processedData[$key] = $value->format('Y-m-d H:i:s');
            } elseif (is_bool($value)) {
                $processedData[$key] = $value ? 1 : 0;
            } else {
                $processedData[$key] = $value;
            }
        }
        return $processedData;
    }


    /**
     * Добавляет новую запись в таблицу базы данных.
     *
     * @param array $data Ассоциативный массив данных, где ключи — это имена столбцов, а значения — соответствующие значения для новой записи.
     * @return bool Возвращает true, если запись была успешно добавлена.
     * @throws Exception Если при добавлении записи возникла ошибка.
     *
     * Пример использования:
     * $user = new ORM('users');
     * $userData = ['name' => 'Jane Doe', 'email' => 'jane.doe@example.com', 'age' => 29];
     * $user->create($userData); // Создаёт новую запись в таблице `users`
     */
    public function create($data)
    {
        if (!$this->tableExists(self::$table)) {
            $this->createTable(self::$table, $data);
        } else {
            $this->addMissingColumns(self::$table, $data);
        }

        $processedData = $this->preprocessData($data);

        $columns = implode(", ", array_keys($processedData));
        $placeholders = ":" . implode(", :", array_keys($processedData));

        $sql = "INSERT INTO " . self::$table . " ($columns) VALUES ($placeholders)";
        $stmt = self::$pdo->prepare($sql);
        foreach ($processedData as $key => &$value) {
            $stmt->bindParam(":$key", $value);
        }

        if (!$stmt->execute()) {
            throw new Exception("Ошибка создания записи: " . implode(", ", $stmt->errorInfo()));
        }

        return true;
    }



    /**
     * Обновляет запись в таблице по заданному ID с переданными данными.
     *
     * @param int $id Уникальный идентификатор записи, которую нужно обновить.
     * @param array $data Ассоциативный массив, где ключи — это имена столбцов, а значения — новые значения для записи.
     * @return bool Возвращает true, если обновление прошло успешно.
     * @throws Exception Если запись с указанным ID не найдена или возникла ошибка при обновлении.
     *
     * Пример использования:
     * $user = new ORM('users');
     * $updateData = ['name' => 'John Doe', 'email' => 'john.doe@example.com'];
     * $user->update(1, $updateData); // Обновляет запись с ID = 1 в таблице `users`
     */
    public function update($id, $data)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE id = :id";
        $stmt = self::$pdo->prepare($sql);
        $stmt->bindParam(":id", $id);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Запись с ID $id не найдена.");
        }

        $processedData = $this->preprocessData($data);
        foreach ($processedData as $key => $value) {
            // Изменяем тип колонки при необходимости
            $this->changeColumnTypeIfNeeded(self::$table, $key, $value);
        }

        $setClause = [];
        foreach ($processedData as $key => $value) {
            $setClause[] = "$key = :$key";
        }
        $setClause = implode(", ", $setClause);

        $sql = "UPDATE " . self::$table . " SET $setClause WHERE id = :id";
        $stmt = self::$pdo->prepare($sql);
        foreach ($processedData as $key => &$value) {
            $stmt->bindParam(":$key", $value);
        }
        $stmt->bindParam(":id", $id);

        if (!$stmt->execute()) {
            throw new Exception("Ошибка обновления записи: " . implode(", ", $stmt->errorInfo()));
        }

        return true;
    }


    /**
     * Обновляет все записи в таблице с переданными данными.
     *
     * @param array $data Ассоциативный массив, где ключи — это имена столбцов, а значения — новые значения для всех записей.
     * @return int Возвращает количество обновлённых записей.
     * @throws Exception Если произошла ошибка при обновлении.
     *
     * Пример использования:
     * $user = new ORM('users');
     * $updateData = ['status' => 'inactive'];
     * $user->updateAll($updateData); // Обновляет поле `status` для всех записей в таблице `users`
     */
    public function updateAll($data)
    {
        if (empty($data)) {
            throw new Exception("Нет данных для обновления.");
        }

        // Предварительная обработка данных
        $processedData = $this->preprocessData($data);

        // Формируем часть запроса SET
        $setClause = [];
        foreach ($processedData as $key => $value) {
            // Проверяем и изменяем типы колонок, если это необходимо
            $this->changeColumnTypeIfNeeded(self::$table, $key, $value);
            $setClause[] = "$key = :$key";
        }
        $setClause = implode(", ", $setClause);

        // Строим и подготавливаем SQL-запрос для обновления всех записей
        $sql = "UPDATE " . self::$table . " SET $setClause";
        $stmt = self::$pdo->prepare($sql);

        // Привязываем параметры
        foreach ($processedData as $key => &$value) {
            $stmt->bindParam(":$key", $value);
        }

        // Выполняем запрос
        if (!$stmt->execute()) {
            throw new Exception("Ошибка обновления записей: " . implode(", ", $stmt->errorInfo()));
        }

        // Возвращаем количество обновленных строк
        return $stmt->rowCount();
    }



    /**
     * Обновляет все записи в таблице, где указанные поля пустые.
     *
     * @param array $data Ассоциативный массив, где ключи — это имена столбцов, а значения — новые значения для обновления.
     * @param array $conditions Ассоциативный массив с условиями, где ключи — это имена столбцов, по которым будут применены условия.
     * @return int Возвращает количество обновлённых записей.
     * @throws Exception Если произошла ошибка при обновлении.
     *
     * Пример использования:
     * $user = new ORM('users');
     * $updateData = ['email' => 'default@example.com'];
     * $conditions = ['email' => null, 'phone' => ''];
     * $updatedCount = $user->updateWhereEmpty($updateData, $conditions); // Обновляет `email` для записей, где `email` и `phone` пустые
     */
    public function updateWhereEmpty($data, $conditions)
    {
        if (empty($data)) {
            throw new Exception("Нет данных для обновления.");
        }

        // Формируем часть запроса SET
        $processedData = $this->preprocessData($data);
        $setClause = [];
        foreach ($processedData as $key => $value) {
            // Проверяем и изменяем типы колонок, если это необходимо
            $this->changeColumnTypeIfNeeded(self::$table, $key, $value);
            $setClause[] = "$key = :$key";
        }
        $setClause = implode(", ", $setClause);

        // Формируем часть запроса WHERE
        $whereClause = [];
        foreach ($conditions as $key => $value) {
            // Предполагается, что если значение пустое, то поле должно быть пустым (NULL или пустая строка)
            if (is_null($value)) {
                $whereClause[] = "$key IS NULL";
            } elseif ($value === '') {
                $whereClause[] = "$key = ''";
            }
        }
        $whereClause = implode(" OR ", $whereClause);

        // Строим и подготавливаем SQL-запрос для обновления по условиям
        $sql = "UPDATE " . self::$table . " SET $setClause WHERE $whereClause";
        $stmt = self::$pdo->prepare($sql);

        // Привязываем параметры
        foreach ($processedData as $key => &$value) {
            $stmt->bindParam(":$key", $value);
        }

        // Выполняем запрос
        if (!$stmt->execute()) {
            throw new Exception("Ошибка обновления записей: " . implode(", ", $stmt->errorInfo()));
        }

        // Возвращаем количество обновленных строк
        return $stmt->rowCount();
    }




    /**
     * Обновляет записи в таблице, которые соответствуют заданным условиям.
     *
     * @param array $data Ассоциативный массив, где ключи — это имена столбцов, а значения — новые значения для обновления.
     * @param array $conditions Ассоциативный массив с условиями, где ключи — это имена столбцов, а значения — значения для замены (Обнолвоения).
     * @return int Возвращает количество обновлённых записей.
     * @throws Exception Если произошла ошибка при обновлении.
     *
     * Пример использования:
     * $user = new ORM('users');
     * $updateData = ['photo' => 'photo.png'];
     * $conditions = ['photo' => 'photo.jpg'];
     * $updatedCount = $user->updateWhere($updateData, $conditions); // Обновляет photo с photo.jpg на photo.png
     */
    public function updateWhere($data, $conditions)
    {
        if (empty($data)) {
            throw new Exception("Нет данных для обновления.");
        }

        // Формируем часть запроса SET
        $processedData = $this->preprocessData($data);
        $setClause = [];
        foreach ($processedData as $key => $value) {
            // Проверяем и изменяем типы колонок, если это необходимо
            $this->changeColumnTypeIfNeeded(self::$table, $key, $value);
            $setClause[] = "$key = :$key";
        }
        $setClause = implode(", ", $setClause);

        // Формируем часть запроса WHERE
        $whereClause = [];
        foreach ($conditions as $key => $value) {
            $whereClause[] = "$key = :cond_$key";
        }
        $whereClause = implode(" AND ", $whereClause);

        // Строим и подготавливаем SQL-запрос для обновления по условиям
        $sql = "UPDATE " . self::$table . " SET $setClause WHERE $whereClause";
        $stmt = self::$pdo->prepare($sql);

        // Привязываем параметры для обновления
        foreach ($processedData as $key => &$value) {
            $stmt->bindParam(":$key", $value);
        }

        // Привязываем параметры для условий
        foreach ($conditions as $key => &$value) {
            $stmt->bindParam(":cond_$key", $value);
        }

        // Выполняем запрос
        if (!$stmt->execute()) {
            throw new Exception("Ошибка обновления записей: " . implode(", ", $stmt->errorInfo()));
        }

        // Возвращаем количество обновленных строк
        return $stmt->rowCount();
    }



    /**
     * Находит записи в таблице по указанному столбцу и значению с возможностью исключения определённых полей.
     *
     * @param string $column Имя столбца для поиска.
     * @param mixed $value Значение для поиска в указанном столбце.
     * @param array $excludeFields Массив полей, которые следует исключить из результата.
     * @return array Возвращает массив найденных записей без исключённых полей.
     * @throws Exception Если произошла ошибка при выполнении запроса.
     *
     * Пример использования:
     * $comments = $user->findByColumn('email', 'example@example.com', ['password', 'created_at']); // Возвращает записи с указанным email без полей password и created_at
     */
    public function findByColumn($column, $value, $excludeFields = [])
    {
        // Проверяем, что имя столбца не пустое
        if (empty($column)) {
            throw new Exception("Имя столбца не может быть пустым.");
        }

        // Формируем список полей для выборки
        $fields = '*'; // По умолчанию выбираем все поля
        if (!empty($excludeFields)) {
            $allFields = $this->getTableFields(); // Получаем все поля таблицы
            $fieldsArray = array_diff($allFields, $excludeFields); // Исключаем ненужные поля
            $fields = implode(", ", $fieldsArray); // Формируем строку с оставшимися полями
        }

        // Формируем SQL-запрос для поиска
        $sql = "SELECT $fields FROM " . self::$table . " WHERE $column = :value";
        $stmt = self::$pdo->prepare($sql);

        // Привязываем параметр
        $stmt->bindParam(':value', $value);

        // Выполняем запрос
        if (!$stmt->execute()) {
            throw new Exception("Ошибка выполнения запроса: " . implode(", ", $stmt->errorInfo()));
        }

        // Возвращаем массив найденных записей
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получает все поля таблицы.
     *
     * @return array Возвращает массив имен полей таблицы.
     */
    protected function getTableFields()
    {
        $sql = "SHOW COLUMNS FROM " . self::$table;
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $columns;
    }



    /**
     * Находит запись в таблице по заданному ID.
     *
     * @param int $id Уникальный идентификатор записи.
     * @return array Ассоциативный массив с данными записи.
     * @throws Exception Если запись с указанным ID не найдена.
     *
     * Пример использования:
     * $user = new ORM('users');
     * $record = $user->find(1); // Найдет запись с ID = 1 в таблице `users`
     */
    public function find($id)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE id = :id";
        $stmt = self::$pdo->prepare($sql);
        $stmt->bindParam(":id", $id);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Запись с ID $id не найдена.");
        }

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    /**
     * Получает все записи из таблицы.
     *
     * @return array Двумерный массив с данными всех записей из таблицы.
     *
     * Пример использования:
     * $user = new ORM('users');
     * $allUsers = $user->findAll(); // Получает все записи из таблицы `users`
     */
    public function findAll()
    {
        $sql = "SELECT * FROM " . self::$table;
        $stmt = self::$pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * Выполняет произвольный SQL-запрос с переданными параметрами.
     *
     * @param string $sql SQL-запрос, который нужно выполнить.
     * @param array $params Ассоциативный массив параметров для привязки к запросу.
     * @return array|int Возвращает массив данных для запросов SELECT или количество затронутых строк для запросов INSERT, UPDATE, DELETE.
     * @throws Exception Если запрос завершился с ошибкой, выбрасывается исключение с сообщением об ошибке.
     *
     * Пример использования:
     * $results = ORM::query("SELECT * FROM users WHERE age > :age", ['age' => 18]);
     */
    public function query($sql, $params = [])
    {
        try {
            $stmt = self::$pdo->prepare($sql); // Подготавливаем SQL-запрос
            foreach ($params as $key => &$value) {
                // Привязываем параметры к запросу
                $stmt->bindParam(":$key", $value);
            }

            $stmt->execute(); // Выполняем запрос

            // Определяем тип запроса
            if (stripos(trim($sql), 'SELECT') === 0) {
                // Если это SELECT-запрос, возвращаем результат в виде массива
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Для других запросов возвращаем количество затронутых строк
                return $stmt->rowCount();
            }
        } catch (PDOException $e) {
            throw new Exception("Ошибка выполнения запроса: " . $e->getMessage());
        }
    }


    /**
     * Удаляет запись из таблицы по заданному ID.
     *
     * @param int $id Уникальный идентификатор записи, которую нужно удалить.
     * @return bool Возвращает true, если удаление прошло успешно.
     * @throws Exception Если запись с указанным ID не найдена или возникла ошибка при удалении.
     *
     * Пример использования:
     * $user = new ORM('users');
     * $user->delete(1); // Удаляет запись с ID = 1 из таблицы `users`
     *
     */
    public function delete($id)
    {
        $sql = "SELECT * FROM " . self::$table . " WHERE id = :id";
        $stmt = self::$pdo->prepare($sql);
        $stmt->bindParam(":id", $id);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Запись с ID $id не найдена.");
        }

        $sql = "DELETE FROM " . self::$table . " WHERE id = :id";
        $stmt = self::$pdo->prepare($sql);
        $stmt->bindParam(":id", $id);

        if (!$stmt->execute()) {
            throw new Exception("Ошибка удаления записи: " . implode(", ", $stmt->errorInfo()));
        }

        return true;
    }
}
