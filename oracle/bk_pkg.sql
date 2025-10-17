-- ===================================================================
-- Paquete: BK_PKG (spec + body)
-- Responsabilidades:
--   - Alta/edición de estrategias y objetos (JSON)
--   - Alta/edición de calendarización
--   - Generación del archivo .rman (UTL_FILE)
--   - Creación/actualización del job DBMS_SCHEDULER
--   - Habilitar/Deshabilitar/Run now
--   - Lectura de bitácoras y “descubrimiento” de objetos
-- ===================================================================

CREATE OR REPLACE PACKAGE bk_pkg AS

  -- ==========
  -- Estrategia
  -- ==========
  PROCEDURE upsert_strategy(
    p_strategy_id     IN OUT NUMBER,
    p_client_name     IN     VARCHAR2,
    p_db_alias        IN     VARCHAR2,
    p_name_code       IN     VARCHAR2,
    p_backup_type     IN     VARCHAR2,   -- FULL|PARCIAL|INCREMENTAL|INCOMPLETO
    p_include_ctlfile IN     CHAR,       -- 'S'/'N'
    p_include_logfile IN     CHAR        -- 'S'/'N'
  );

  -- Objetos (entrada como JSON):
  -- [
  --   {"tablespace":"USERS","datafile":null,"size_mb":100,"selected":"S"},
  --   {"tablespace":null,"datafile":"/u01/oradata/XE/tools01.dbf","size_mb":2048,"selected":"S"}
  -- ]
  PROCEDURE set_strategy_objects(
    p_strategy_id IN NUMBER,
    p_objects_json IN CLOB
  );

  -- =============
  -- Calendarización
  -- =============
  PROCEDURE upsert_schedule(
    p_schedule_id IN OUT NUMBER,
    p_strategy_id IN     NUMBER,
    p_freq        IN     VARCHAR2,   -- DAILY|WEEKLY|MONTHLY|ONCE
    p_start_time  IN     TIMESTAMP,
    p_byday       IN     VARCHAR2,
    p_byhour      IN     VARCHAR2,
    p_byminute    IN     VARCHAR2,
    p_enabled     IN     CHAR        -- 'S'/'N'
  );

  -- ======================
  -- Scheduler / RMAN files
  -- ======================
  PROCEDURE create_or_replace_job(p_strategy_id IN NUMBER);
  PROCEDURE enable_job(p_strategy_id IN NUMBER);
  PROCEDURE disable_job(p_strategy_id IN NUMBER);
  PROCEDURE run_now(p_strategy_id IN NUMBER);

  -- =======
  -- Logs API
  -- =======
  FUNCTION get_logs(p_strategy_id IN NUMBER) RETURN SYS_REFCURSOR;

  -- ==================
  -- Descubrimiento DB
  -- ==================
  FUNCTION list_tablespaces RETURN SYS_REFCURSOR;
  FUNCTION list_datafiles  RETURN SYS_REFCURSOR;

END bk_pkg;
/
SHOW ERRORS

CREATE OR REPLACE PACKAGE BODY bk_pkg AS

  -- Parámetros “de entorno” (ajusta rutas según tu instalación)
  c_directory_name CONSTANT VARCHAR2(30) := 'RMAN_DIR';  -- Oracle DIRECTORY
  c_job_prefix     CONSTANT VARCHAR2(20) := 'BK_STRAT_';
  c_rman_bin       CONSTANT VARCHAR2(260):= 'rman';      -- en Linux suele estar en PATH del usuario Oracle

  /* Utilidad: obtiene la ruta física del DIRECTORY RMAN_DIR */
  FUNCTION get_directory_path RETURN VARCHAR2 IS
    v_path VARCHAR2(4000);
  BEGIN
    SELECT directory_path INTO v_path
      FROM all_directories
     WHERE directory_name = c_directory_name;
    RETURN v_path;
  EXCEPTION
    WHEN NO_DATA_FOUND THEN
      RAISE_APPLICATION_ERROR(-20001, 'DIRECTORY RMAN_DIR no existe o no es visible.');
  END;

  /* Utilidad: nombre del archivo .rman */
  FUNCTION rman_cmdfile_name(p_strategy_id NUMBER) RETURN VARCHAR2 IS
  BEGIN
    RETURN 'strat_' || p_strategy_id || '.rman';
  END;

  /* Utilidad: construye BY* para repeat_interval */
  FUNCTION build_repeat_interval(
    p_freq    VARCHAR2,
    p_byday   VARCHAR2,
    p_byhour  VARCHAR2,
    p_byminute VARCHAR2,
    p_start   TIMESTAMP
  ) RETURN VARCHAR2 IS
    v VARCHAR2(4000);
    v_main VARCHAR2(4000);
    v_by   VARCHAR2(4000);
  BEGIN
    v_main := 'FREQ='||UPPER(p_freq);
    IF UPPER(p_freq) = 'WEEKLY' AND p_byday IS NOT NULL THEN
      v_by := v_by || ';BYDAY=' || REPLACE(UPPER(p_byday),' ','');
    END IF;
    IF p_byhour IS NOT NULL THEN
      v_by := v_by || ';BYHOUR=' || REPLACE(p_byhour,' ','');
    ELSE
      v_by := v_by || ';BYHOUR=' || TO_CHAR(EXTRACT(HOUR FROM p_start));
    END IF;
    IF p_byminute IS NOT NULL THEN
      v_by := v_by || ';BYMINUTE=' || REPLACE(p_byminute,' ','');
    ELSE
      v_by := v_by || ';BYMINUTE=' || TO_CHAR(EXTRACT(MINUTE FROM p_start));
    END IF;
    v_by := v_by || ';BYSECOND=0';
    RETURN v_main || v_by;
  END;

  /* ==========
     Estrategia
     ========== */
  PROCEDURE upsert_strategy(
    p_strategy_id     IN OUT NUMBER,
    p_client_name     IN     VARCHAR2,
    p_db_alias        IN     VARCHAR2,
    p_name_code       IN     VARCHAR2,
    p_backup_type     IN     VARCHAR2,
    p_include_ctlfile IN     CHAR,
    p_include_logfile IN     CHAR
  ) IS
  BEGIN
    IF p_strategy_id IS NULL THEN
      INSERT INTO BK_STRATEGY (client_name, db_alias, name_code, backup_type, include_ctlfile, include_logfile)
      VALUES (p_client_name, p_db_alias, p_name_code, UPPER(p_backup_type), p_include_ctlfile, p_include_logfile)
      RETURNING strategy_id INTO p_strategy_id;
    ELSE
      UPDATE BK_STRATEGY
         SET client_name     = p_client_name,
             db_alias        = p_db_alias,
             name_code       = p_name_code,
             backup_type     = UPPER(p_backup_type),
             include_ctlfile = p_include_ctlfile,
             include_logfile = p_include_logfile
       WHERE strategy_id = p_strategy_id;
      IF SQL%ROWCOUNT = 0 THEN
        RAISE_APPLICATION_ERROR(-20002, 'Strategy no existe: '||p_strategy_id);
      END IF;
    END IF;

    -- Regla: FULL => eliminar objetos asociados (no aplica selección)
    IF UPPER(p_backup_type) = 'FULL' THEN
      DELETE FROM BK_STRATEGY_OBJECT WHERE strategy_id = p_strategy_id;
    END IF;
  END;

  /* Objetos desde JSON */
  PROCEDURE set_strategy_objects(
    p_strategy_id IN NUMBER,
    p_objects_json IN CLOB
  ) IS
  BEGIN
    -- limpiamos y reinsertamos (idempotente por estrategia)
    DELETE FROM BK_STRATEGY_OBJECT WHERE strategy_id = p_strategy_id;

    INSERT INTO BK_STRATEGY_OBJECT(strategy_id, tablespace_name, datafile_path, size_mb, selected)
    SELECT p_strategy_id,
           jt.tablespace,
           jt.datafile,
           jt.size_mb,
           NVL(jt.selected, 'S')
      FROM JSON_TABLE(
             p_objects_json,
             '$[*]' COLUMNS (
               tablespace  VARCHAR2(30)  PATH '$.tablespace',
               datafile    VARCHAR2(512) PATH '$.datafile',
               size_mb     NUMBER        PATH '$.size_mb',
               selected    VARCHAR2(1)   PATH '$.selected'
             )
           ) jt;

    -- Validación básica: si NO es FULL, exigir >=1 objeto
    DECLARE
      v_type  VARCHAR2(20);
      v_count NUMBER;
    BEGIN
      SELECT backup_type INTO v_type FROM BK_STRATEGY WHERE strategy_id = p_strategy_id;
      IF v_type <> 'FULL' THEN
        SELECT COUNT(*) INTO v_count FROM BK_STRATEGY_OBJECT WHERE strategy_id = p_strategy_id AND selected = 'S';
        IF v_count = 0 THEN
          RAISE_APPLICATION_ERROR(-20003, 'La estrategia requiere al menos 1 objeto seleccionado.');
        END IF;
      END IF;
    END;
  END;

  /* =============
     Calendarización
     ============= */
  PROCEDURE upsert_schedule(
    p_schedule_id IN OUT NUMBER,
    p_strategy_id IN     NUMBER,
    p_freq        IN     VARCHAR2,
    p_start_time  IN     TIMESTAMP,
    p_byday       IN     VARCHAR2,
    p_byhour      IN     VARCHAR2,
    p_byminute    IN     VARCHAR2,
    p_enabled     IN     CHAR
  ) IS
  BEGIN
    IF p_schedule_id IS NULL THEN
      INSERT INTO BK_SCHEDULE(strategy_id, freq, start_time, byday, byhour, byminute, enabled)
      VALUES (p_strategy_id, UPPER(p_freq), p_start_time, UPPER(p_byday), p_byhour, p_byminute, p_enabled)
      RETURNING schedule_id INTO p_schedule_id;
    ELSE
      UPDATE BK_SCHEDULE
         SET freq       = UPPER(p_freq),
             start_time = p_start_time,
             byday      = UPPER(p_byday),
             byhour     = p_byhour,
             byminute   = p_byminute,
             enabled    = p_enabled
       WHERE schedule_id = p_schedule_id;
      IF SQL%ROWCOUNT = 0 THEN
        RAISE_APPLICATION_ERROR(-20004, 'Schedule no existe: '||p_schedule_id);
      END IF;
    END IF;
  END;

  /* Genera archivo .rman con UTL_FILE según la estrategia */
  PROCEDURE write_rman_file(p_strategy_id NUMBER) IS
    v_file     UTL_FILE.FILE_TYPE;
    v_filename VARCHAR2(200) := rman_cmdfile_name(p_strategy_id);
    v_dir_path VARCHAR2(4000) := get_directory_path;
    v_type     VARCHAR2(20);
    v_ctl      CHAR(1);
    v_log      CHAR(1);
    v_line     VARCHAR2(4000);
  BEGIN
    SELECT backup_type, include_ctlfile, include_logfile
      INTO v_type, v_ctl, v_log
      FROM BK_STRATEGY
     WHERE strategy_id = p_strategy_id;

    v_file := UTL_FILE.FOPEN(c_directory_name, v_filename, 'w');

    UTL_FILE.PUT_LINE(v_file, 'CONNECT TARGET /');
    UTL_FILE.PUT_LINE(v_file, 'RUN {');

    IF v_type = 'FULL' THEN
      UTL_FILE.PUT_LINE(v_file, '  BACKUP AS BACKUPSET DATABASE;');
    ELSIF v_type IN ('PARCIAL','INCOMPLETO','INCREMENTAL') THEN
      -- Construir lista de objetos seleccionados
      DECLARE
        CURSOR c_obj IS
          SELECT tablespace_name, datafile_path
            FROM BK_STRATEGY_OBJECT
           WHERE strategy_id = p_strategy_id
             AND selected = 'S';
        v_ts  VARCHAR2(30);
        v_df  VARCHAR2(512);
        v_has_ts BOOLEAN := FALSE;
        v_has_df BOOLEAN := FALSE;
        v_list_ts VARCHAR2(4000);
        v_list_df VARCHAR2(32767);
      BEGIN
        FOR r IN c_obj LOOP
          IF r.tablespace_name IS NOT NULL THEN
            v_has_ts := TRUE;
            v_list_ts := CASE WHEN v_list_ts IS NULL THEN r.tablespace_name
                              ELSE v_list_ts||','||r.tablespace_name END;
          ELSIF r.datafile_path IS NOT NULL THEN
            v_has_df := TRUE;
            v_list_df := CASE WHEN v_list_df IS NULL THEN ''''||r.datafile_path||''''
                              ELSE v_list_df||','''||r.datafile_path||'''' END;
          END IF;
        END LOOP;

        IF v_type = 'INCREMENTAL' THEN
          -- Nivel 0 por defecto (puedes parametrizar nivel si lo agregas luego)
          IF v_has_ts THEN
            UTL_FILE.PUT_LINE(v_file, '  BACKUP AS BACKUPSET INCREMENTAL LEVEL 0 TABLESPACE '||v_list_ts||';');
          END IF;
          IF v_has_df THEN
            UTL_FILE.PUT_LINE(v_file, '  BACKUP AS BACKUPSET INCREMENTAL LEVEL 0 DATAFILE '||v_list_df||';');
          END IF;
        ELSE
          IF v_has_ts THEN
            UTL_FILE.PUT_LINE(v_file, '  BACKUP AS BACKUPSET TABLESPACE '||v_list_ts||';');
          END IF;
          IF v_has_df THEN
            UTL_FILE.PUT_LINE(v_file, '  BACKUP AS BACKUPSET DATAFILE '||v_list_df||';');
          END IF;
        END IF;
      END;
    END IF;

    IF v_ctl = 'S' THEN
      UTL_FILE.PUT_LINE(v_file, '  BACKUP CURRENT CONTROLFILE;');
    END IF;
    IF v_log = 'S' THEN
      UTL_FILE.PUT_LINE(v_file, '  BACKUP ARCHIVELOG ALL NOT BACKED UP;');
    END IF;

    UTL_FILE.PUT_LINE(v_file, '}');
    UTL_FILE.FCLOSE(v_file);
  EXCEPTION
    WHEN OTHERS THEN
      BEGIN
        IF UTL_FILE.IS_OPEN(v_file) THEN UTL_FILE.FCLOSE(v_file); END IF;
      EXCEPTION WHEN OTHERS THEN NULL; END;
      RAISE;
  END;

  /* Crea/actualiza el JOB del scheduler para la estrategia */
  PROCEDURE create_or_replace_job(p_strategy_id IN NUMBER) IS
    v_job_name       VARCHAR2(128) := c_job_prefix || p_strategy_id;
    v_freq           VARCHAR2(20);
    v_start          TIMESTAMP;
    v_byday          VARCHAR2(50);
    v_byhour         VARCHAR2(50);
    v_byminute       VARCHAR2(50);
    v_enabled        CHAR(1);
    v_repeat         VARCHAR2(4000);
    v_cmdfile        VARCHAR2(200) := rman_cmdfile_name(p_strategy_id);
    v_dir_path       VARCHAR2(4000) := get_directory_path;
  BEGIN
    -- 1) Verificar schedule
    SELECT freq, start_time, NVL(byday,''), NVL(byhour,''), NVL(byminute,''), enabled
      INTO v_freq, v_start, v_byday, v_byhour, v_byminute, v_enabled
      FROM BK_SCHEDULE
     WHERE strategy_id = p_strategy_id
       AND ROWNUM = 1;

    v_repeat := build_repeat_interval(v_freq, v_byday, v_byhour, v_byminute, v_start);

    -- 2) Generar el archivo .rman
    write_rman_file(p_strategy_id);

    -- 3) Si existe, eliminar/actualizar
    BEGIN
      DBMS_SCHEDULER.DROP_JOB(job_name => v_job_name, force => TRUE);
    EXCEPTION WHEN OTHERS THEN NULL; END;

    -- 4) Crear job de tipo EXECUTABLE que invoque RMAN con el command file
    --    Advertencia: requiere configuración de external jobs en el SO.
    -- 4) Crear job de tipo EXECUTABLE que invoque RMAN con el command file
--    Advertencia: requiere privilegio CREATE EXTERNAL JOB y servicio del Scheduler activo
DBMS_SCHEDULER.CREATE_JOB (
  job_name        => v_job_name,
  job_type        => 'PLSQL_BLOCK', 
  job_action      => 'BEGIN NULL; END;',  -- Simulación segura sin usar rman.exe
  start_date      => v_start,
  repeat_interval => v_repeat,
  enabled         => TRUE,                -- Activa el job automáticamente
  auto_drop       => FALSE,               -- No se borra tras ejecutarse
  comments        => 'Simulación de backup para estrategia ' || p_strategy_id
);



    -- args típicos: 'target', 'nocatalog', '@/ruta/strat_X.rman'
    DBMS_SCHEDULER.SET_JOB_ARGUMENT_VALUE(v_job_name, 1, 'target /');
    DBMS_SCHEDULER.SET_JOB_ARGUMENT_VALUE(v_job_name, 2, 'nocatalog');
    DBMS_SCHEDULER.SET_JOB_ARGUMENT_VALUE(v_job_name, 3, '@'||v_dir_path||'/'||v_cmdfile);

    IF v_enabled = 'S' THEN
      DBMS_SCHEDULER.ENABLE(v_job_name);
    END IF;

    -- Registro en BK_LOG (evento de definición/actualización)
    INSERT INTO BK_LOG(strategy_id, started_at, finished_at, status, message)
    VALUES (p_strategy_id, SYSTIMESTAMP, SYSTIMESTAMP, 'SUBMITTED',
            'Job '||v_job_name||' creado/actualizado con '||v_repeat);
  EXCEPTION
    WHEN NO_DATA_FOUND THEN
      RAISE_APPLICATION_ERROR(-20005,'No existe BK_SCHEDULE para la estrategia '||p_strategy_id);
  END;

  PROCEDURE enable_job(p_strategy_id IN NUMBER) IS
    v_job_name VARCHAR2(128) := c_job_prefix || p_strategy_id;
  BEGIN
    DBMS_SCHEDULER.ENABLE(v_job_name);
  END;

  PROCEDURE disable_job(p_strategy_id IN NUMBER) IS
    v_job_name VARCHAR2(128) := c_job_prefix || p_strategy_id;
  BEGIN
    DBMS_SCHEDULER.DISABLE(v_job_name, force => TRUE);
  END;

  PROCEDURE run_now(p_strategy_id IN NUMBER) IS
    v_job_name VARCHAR2(128) := c_job_prefix || p_strategy_id;
  BEGIN
    INSERT INTO BK_LOG(strategy_id, started_at, status, message)
    VALUES (p_strategy_id, SYSTIMESTAMP, 'SUBMITTED', 'Ejecución manual solicitada');
    DBMS_SCHEDULER.RUN_JOB(v_job_name, use_current_session => FALSE);
    INSERT INTO BK_LOG(strategy_id, finished_at, status, message)
    VALUES (p_strategy_id, SYSTIMESTAMP, 'SUBMITTED', 'RUN_JOB enviado al scheduler');
  END;

  /* =======
     Logs API
     ======= */
  FUNCTION get_logs(p_strategy_id IN NUMBER) RETURN SYS_REFCURSOR IS
    rc SYS_REFCURSOR;
  BEGIN
    OPEN rc FOR
      SELECT log_id, strategy_id, run_id, started_at, finished_at, status,
             DBMS_LOB.SUBSTR(message, 4000, 1) AS message,
             created_at
        FROM BK_LOG
       WHERE strategy_id = p_strategy_id
       ORDER BY log_id DESC;
    RETURN rc;
  END;

  /* ==================
     Descubrimiento DB
     ================== */
  FUNCTION list_tablespaces RETURN SYS_REFCURSOR IS
    rc SYS_REFCURSOR;
  BEGIN
    OPEN rc FOR
      SELECT tablespace_name FROM user_tablespaces ORDER BY tablespace_name;
    RETURN rc;
  END;

    FUNCTION list_datafiles RETURN SYS_REFCURSOR IS
    rc SYS_REFCURSOR;
  BEGIN
    OPEN rc FOR
      SELECT df.name AS datafile_path,
             ts.name AS tablespace_name,
             ROUND(df.bytes/1024/1024) AS size_mb
        FROM v$datafile df
        JOIN v$tablespace ts ON df.ts# = ts.ts#
       ORDER BY ts.name, df.name;
    RETURN rc;
  END;



END bk_pkg;
/
SHOW ERRORS
