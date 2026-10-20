import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
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

const audit_runs = {
    progress: Object.assign(progress, progress),
}

export default audit_runs