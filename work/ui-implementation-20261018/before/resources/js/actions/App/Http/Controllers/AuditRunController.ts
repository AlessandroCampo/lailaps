import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\AuditRunController::store
* @see app/Http/Controllers/AuditRunController.php:27
* @route '/projects/{project}/sources/{source}/audit-runs'
*/
export const store = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/projects/{project}/sources/{source}/audit-runs',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\AuditRunController::store
* @see app/Http/Controllers/AuditRunController.php:27
* @route '/projects/{project}/sources/{source}/audit-runs'
*/
store.url = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            project: args[0],
            source: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        project: typeof args.project === 'object'
        ? args.project.id
        : args.project,
        source: typeof args.source === 'object'
        ? args.source.id
        : args.source,
    }

    return store.definition.url
            .replace('{project}', parsedArgs.project.toString())
            .replace('{source}', parsedArgs.source.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AuditRunController::store
* @see app/Http/Controllers/AuditRunController.php:27
* @route '/projects/{project}/sources/{source}/audit-runs'
*/
store.post = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AuditRunController::store
* @see app/Http/Controllers/AuditRunController.php:27
* @route '/projects/{project}/sources/{source}/audit-runs'
*/
const storeForm = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AuditRunController::store
* @see app/Http/Controllers/AuditRunController.php:27
* @route '/projects/{project}/sources/{source}/audit-runs'
*/
storeForm.post = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\AuditRunController::progress
* @see app/Http/Controllers/AuditRunController.php:20
* @route '/audit-runs/{auditRun}/progress'
*/
export const progress = (args: { auditRun: string | { id: string } } | [auditRun: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: progress.url(args, options),
    method: 'get',
})

progress.definition = {
    methods: ["get","head"],
    url: '/audit-runs/{auditRun}/progress',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AuditRunController::progress
* @see app/Http/Controllers/AuditRunController.php:20
* @route '/audit-runs/{auditRun}/progress'
*/
progress.url = (args: { auditRun: string | { id: string } } | [auditRun: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { auditRun: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { auditRun: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            auditRun: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        auditRun: typeof args.auditRun === 'object'
        ? args.auditRun.id
        : args.auditRun,
    }

    return progress.definition.url
            .replace('{auditRun}', parsedArgs.auditRun.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AuditRunController::progress
* @see app/Http/Controllers/AuditRunController.php:20
* @route '/audit-runs/{auditRun}/progress'
*/
progress.get = (args: { auditRun: string | { id: string } } | [auditRun: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: progress.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AuditRunController::progress
* @see app/Http/Controllers/AuditRunController.php:20
* @route '/audit-runs/{auditRun}/progress'
*/
progress.head = (args: { auditRun: string | { id: string } } | [auditRun: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: progress.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AuditRunController::progress
* @see app/Http/Controllers/AuditRunController.php:20
* @route '/audit-runs/{auditRun}/progress'
*/
const progressForm = (args: { auditRun: string | { id: string } } | [auditRun: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: progress.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AuditRunController::progress
* @see app/Http/Controllers/AuditRunController.php:20
* @route '/audit-runs/{auditRun}/progress'
*/
progressForm.get = (args: { auditRun: string | { id: string } } | [auditRun: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: progress.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AuditRunController::progress
* @see app/Http/Controllers/AuditRunController.php:20
* @route '/audit-runs/{auditRun}/progress'
*/
progressForm.head = (args: { auditRun: string | { id: string } } | [auditRun: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: progress.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

progress.form = progressForm

const AuditRunController = { store, progress }

export default AuditRunController