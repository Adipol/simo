-- Evidence only. One PG17 SELECT, fixed public namespace; no session changes or OID output.
WITH relations AS (
    SELECT c.*, pg_catalog.format('%I.%I', n.nspname, c.relname) AS identity
    FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
), constraints AS (
    SELECT c.*, r.identity AS relation_identity,
        r.identity || '.' || pg_catalog.quote_ident(c.conname) AS identity
    FROM pg_catalog.pg_constraint c JOIN relations r ON r.oid = c.conrelid
), triggers AS (
    SELECT t.*, r.identity AS relation_identity, r.relowner,
        -- PG17 CreateTrigger's own RI name only. Anomalous/custom names remain verbatim.
        t.tgisinternal AND c.contype = 'f' AND pn.nspname = 'pg_catalog'
            AND p.proname IN ('RI_FKey_check_ins', 'RI_FKey_check_upd', 'RI_FKey_noaction_del',
                'RI_FKey_noaction_upd', 'RI_FKey_restrict_del', 'RI_FKey_restrict_upd',
                'RI_FKey_cascade_del', 'RI_FKey_cascade_upd', 'RI_FKey_setnull_del',
                'RI_FKey_setnull_upd', 'RI_FKey_setdefault_del', 'RI_FKey_setdefault_upd')
            AND t.tgname = 'RI_ConstraintTrigger_' || CASE WHEN p.proname IN ('RI_FKey_check_ins', 'RI_FKey_check_upd') THEN 'c' ELSE 'a' END || '_' || t.oid::text
            AS generated_ri_name
    FROM pg_catalog.pg_trigger t JOIN relations r ON r.oid = t.tgrelid
    LEFT JOIN pg_catalog.pg_constraint c ON c.oid = t.tgconstraint
    JOIN pg_catalog.pg_proc p ON p.oid = t.tgfoid
    JOIN pg_catalog.pg_namespace pn ON pn.oid = p.pronamespace
), objects AS (
    -- Direct namespace dependencies retain kinds not modeled below (types, operators, ...).
    SELECT DISTINCT i.type, i.schema, i.name, i.identity
    FROM pg_catalog.pg_depend d
    JOIN pg_catalog.pg_namespace n ON n.oid = d.refobjid
    CROSS JOIN LATERAL pg_catalog.pg_identify_object(d.classid, d.objid, d.objsubid) i
    WHERE d.refclassid = 'pg_catalog.pg_namespace'::pg_catalog.regclass AND n.nspname = 'public'
)
SELECT pg_catalog.jsonb_build_object(
    'metadata', pg_catalog.jsonb_build_object('projection', 'pg17-native-collection-v1',
        'server_version_num', pg_catalog.current_setting('server_version_num')::integer,
        'database', pg_catalog.current_database(), 'user', current_user,
        'schemas', pg_catalog.current_schemas(false)),
    'relations', COALESCE((SELECT pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
        'identity', r.identity, 'name', r.relname, 'kind', r.relkind,
        'owner', pg_catalog.pg_get_userbyid(r.relowner), 'persistence', r.relpersistence,
        'rls', r.relrowsecurity, 'force_rls', r.relforcerowsecurity,
        'partition', r.relispartition, 'partition_bound', pg_catalog.pg_get_expr(r.relpartbound, r.oid),
        'partition_key', pg_catalog.pg_get_partkeydef(r.oid),
        'parents', ARRAY(SELECT (pg_catalog.pg_identify_object('pg_catalog.pg_class'::pg_catalog.regclass, h.inhparent, 0)).identity
            FROM pg_catalog.pg_inherits h WHERE h.inhrelid = r.oid ORDER BY h.inhseqno),
        'access_method', am.amname, 'tablespace', ts.spcname, 'options', r.reloptions, 'acl', r.relacl,
        'replica_identity', r.relreplident, 'checks', r.relchecks,
        'has_rules', r.relhasrules, 'has_triggers', r.relhastriggers,
        'view_definition', CASE WHEN r.relkind IN ('v', 'm') THEN pg_catalog.pg_get_viewdef(r.oid, false) END
    ) ORDER BY r.identity COLLATE "C") FROM relations r
    LEFT JOIN pg_catalog.pg_am am ON am.oid = r.relam
    LEFT JOIN pg_catalog.pg_tablespace ts ON ts.oid = r.reltablespace), '[]'::jsonb),
    'sequences', COALESCE((SELECT pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
        'identity', r.identity, 'type', pg_catalog.format_type(s.seqtypid, NULL),
        'start', s.seqstart, 'increment', s.seqincrement, 'min', s.seqmin, 'max', s.seqmax,
        'cache', s.seqcache, 'cycle', s.seqcycle,
        'ownership', ARRAY(SELECT (pg_catalog.pg_identify_object(d.refclassid, d.refobjid, d.refobjsubid)).identity
            FROM pg_catalog.pg_depend d WHERE d.classid = 'pg_catalog.pg_class'::pg_catalog.regclass
            AND d.objid = s.seqrelid AND d.deptype IN ('a', 'i') ORDER BY 1)
    ) ORDER BY r.identity COLLATE "C") FROM pg_catalog.pg_sequence s JOIN relations r ON r.oid = s.seqrelid), '[]'::jsonb),
    'columns', COALESCE((SELECT pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
        'identity', r.identity || '.' || pg_catalog.quote_ident(a.attname), 'relation', r.identity,
        'name', a.attname, 'ordinal', a.attnum, 'type', pg_catalog.format_type(a.atttypid, a.atttypmod),
        'type_identity', (pg_catalog.pg_identify_object('pg_catalog.pg_type'::pg_catalog.regclass, a.atttypid, 0)).identity,
        'typmod', a.atttypmod, 'dimensions', a.attndims, 'not_null', a.attnotnull, 'identity_mode', a.attidentity,
        'generated', a.attgenerated, 'default', pg_catalog.pg_get_expr(d.adbin, d.adrelid),
        'collation', CASE WHEN a.attcollation <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_collation'::pg_catalog.regclass, a.attcollation, 0)).identity END,
        'local', a.attislocal, 'inheritance_count', a.attinhcount, 'storage', a.attstorage,
        'compression', a.attcompression, 'options', a.attoptions, 'acl', a.attacl
    ) ORDER BY r.identity COLLATE "C", a.attname COLLATE "C") FROM relations r
    JOIN pg_catalog.pg_attribute a ON a.attrelid = r.oid AND a.attnum > 0 AND NOT a.attisdropped
    LEFT JOIN pg_catalog.pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum), '[]'::jsonb),
    'constraints', COALESCE((SELECT pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
        'identity', c.identity, 'relation', c.relation_identity, 'kind', c.contype,
        'definition', pg_catalog.pg_get_constraintdef(c.oid, false), 'validated', c.convalidated,
        'deferrable', c.condeferrable, 'deferred', c.condeferred, 'local', c.conislocal,
        'inheritance_count', c.coninhcount, 'no_inherit', c.connoinherit,
        'update_action', c.confupdtype, 'delete_action', c.confdeltype, 'match', c.confmatchtype,
        'columns', c.conkey, 'referenced_columns', c.confkey, 'delete_set_columns', c.confdelsetcols,
        'index', CASE WHEN c.conindid <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_class'::pg_catalog.regclass, c.conindid, 0)).identity END,
        'referenced_relation', CASE WHEN c.confrelid <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_class'::pg_catalog.regclass, c.confrelid, 0)).identity END,
        'parent', CASE WHEN c.conparentid <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_constraint'::pg_catalog.regclass, c.conparentid, 0)).identity END
    ) ORDER BY c.identity COLLATE "C") FROM constraints c), '[]'::jsonb),
    'indexes', COALESCE((SELECT pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
        'identity', r.identity, 'relation', t.identity, 'owner', pg_catalog.pg_get_userbyid(r.relowner),
        'definition', pg_catalog.pg_get_indexdef(i.indexrelid, 0, false),
        'predicate', pg_catalog.pg_get_expr(i.indpred, i.indrelid),
        'expressions', pg_catalog.pg_get_expr(i.indexprs, i.indrelid), 'keys', i.indkey::smallint[],
        'key_count', i.indnkeyatts, 'attribute_count', i.indnatts, 'options', i.indoption::smallint[],
        'opclasses', ARRAY(SELECT (pg_catalog.pg_identify_object('pg_catalog.pg_opclass'::pg_catalog.regclass, x, 0)).identity FROM pg_catalog.unnest(i.indclass) x),
        'collations', ARRAY(SELECT CASE WHEN x <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_collation'::pg_catalog.regclass, x, 0)).identity END FROM pg_catalog.unnest(i.indcollation) x),
        'valid', i.indisvalid, 'ready', i.indisready, 'live', i.indislive,
        'unique', i.indisunique, 'nulls_not_distinct', i.indnullsnotdistinct,
        'primary', i.indisprimary, 'exclusion', i.indisexclusion, 'immediate', i.indimmediate,
        'clustered', i.indisclustered, 'replica_identity', i.indisreplident
    ) ORDER BY r.identity COLLATE "C") FROM pg_catalog.pg_index i
    JOIN relations r ON r.oid = i.indexrelid JOIN relations t ON t.oid = i.indrelid), '[]'::jsonb),
    'triggers', COALESCE((SELECT pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
        'identity', t.relation_identity || '.' || CASE WHEN t.generated_ri_name THEN c.conname || ':RI:' || t.tgtype::text || ':' || (pg_catalog.pg_identify_object('pg_catalog.pg_proc'::pg_catalog.regclass, t.tgfoid, 0)).identity ELSE t.tgname END,
        'name', CASE WHEN t.generated_ri_name THEN NULL ELSE t.tgname END,
        'generated_ri_name', COALESCE(t.generated_ri_name, false), 'relation', t.relation_identity,
        'relation_owner', pg_catalog.pg_get_userbyid(t.relowner), 'internal', t.tgisinternal,
        'definition', CASE WHEN NOT COALESCE(t.generated_ri_name, false) THEN pg_catalog.pg_get_triggerdef(t.oid, false) END,
        'constraint', CASE WHEN t.tgconstraint <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_constraint'::pg_catalog.regclass, t.tgconstraint, 0)).identity END,
        'constraint_relation', CASE WHEN t.tgconstrrelid <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_class'::pg_catalog.regclass, t.tgconstrrelid, 0)).identity END,
        'function', (pg_catalog.pg_identify_object('pg_catalog.pg_proc'::pg_catalog.regclass, t.tgfoid, 0)).identity,
        'constraint_index', CASE WHEN t.tgconstrindid <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_class'::pg_catalog.regclass, t.tgconstrindid, 0)).identity END,
        'enabled', t.tgenabled, 'flags', t.tgtype, 'arguments', pg_catalog.encode(t.tgargs, 'hex'),
        'argument_count', t.tgnargs, 'columns', t.tgattr::smallint[],
        'deferrable', t.tgdeferrable, 'deferred', t.tginitdeferred,
        'condition', pg_catalog.pg_get_expr(t.tgqual, t.tgrelid),
        'old_transition_table', t.tgoldtable, 'new_transition_table', t.tgnewtable,
        'parent', CASE WHEN t.tgparentid <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_trigger'::pg_catalog.regclass, t.tgparentid, 0)).identity END
    ) ORDER BY t.relation_identity COLLATE "C", c.conname COLLATE "C", t.tgtype,
        (pg_catalog.pg_identify_object('pg_catalog.pg_proc'::pg_catalog.regclass, t.tgfoid, 0)).identity COLLATE "C",
        CASE WHEN NOT COALESCE(t.generated_ri_name, false) THEN t.tgname END COLLATE "C")
    FROM triggers t LEFT JOIN pg_catalog.pg_constraint c ON c.oid = t.tgconstraint), '[]'::jsonb),
    'routines', COALESCE((SELECT pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
        'identity', (pg_catalog.pg_identify_object('pg_catalog.pg_proc'::pg_catalog.regclass, p.oid, 0)).identity,
        'kind', p.prokind, 'owner', pg_catalog.pg_get_userbyid(p.proowner), 'language', l.lanname,
        'arguments', pg_catalog.pg_get_function_arguments(p.oid), 'argument_names', p.proargnames,
        'argument_modes', p.proargmodes, 'result', pg_catalog.pg_get_function_result(p.oid),
        'definition', CASE WHEN p.prokind <> 'a' THEN pg_catalog.pg_get_functiondef(p.oid) END,
        'source', p.prosrc, 'binary', p.probin, 'settings', p.proconfig, 'acl', p.proacl,
        'security_definer', p.prosecdef, 'leakproof', p.proleakproof, 'strict', p.proisstrict,
        'returns_set', p.proretset, 'volatility', p.provolatile, 'parallel', p.proparallel,
        'cost', p.procost, 'rows', p.prorows, 'default_count', p.pronargdefaults,
        'support', CASE WHEN p.prosupport <> 0 THEN (pg_catalog.pg_identify_object('pg_catalog.pg_proc'::pg_catalog.regclass, p.prosupport, 0)).identity END
    ) ORDER BY (pg_catalog.pg_identify_object('pg_catalog.pg_proc'::pg_catalog.regclass, p.oid, 0)).identity COLLATE "C")
    FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
    JOIN pg_catalog.pg_language l ON l.oid = p.prolang WHERE n.nspname = 'public'), '[]'::jsonb),
    'policies', COALESCE((SELECT pg_catalog.jsonb_agg(pg_catalog.jsonb_build_object(
        'identity', r.identity || '.' || pg_catalog.quote_ident(p.polname), 'relation', r.identity,
        'command', p.polcmd, 'permissive', p.polpermissive,
        'roles', ARRAY(SELECT CASE WHEN x = 0 THEN 'PUBLIC' ELSE pg_catalog.pg_get_userbyid(x) END FROM pg_catalog.unnest(p.polroles) x ORDER BY 1),
        'using', pg_catalog.pg_get_expr(p.polqual, p.polrelid), 'check', pg_catalog.pg_get_expr(p.polwithcheck, p.polrelid)
    ) ORDER BY r.identity COLLATE "C", p.polname COLLATE "C")
    FROM pg_catalog.pg_policy p JOIN relations r ON r.oid = p.polrelid), '[]'::jsonb),
    'objects', COALESCE((SELECT pg_catalog.jsonb_agg(pg_catalog.to_jsonb(o) ORDER BY o.type COLLATE "C", o.identity COLLATE "C") FROM objects o), '[]'::jsonb)
) AS catalog;
