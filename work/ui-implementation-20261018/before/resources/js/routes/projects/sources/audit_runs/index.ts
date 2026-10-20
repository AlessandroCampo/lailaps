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

const audit_runs = {
    store: Object.assign(store, store),
}

export default audit_runs