import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ProjectSourceController::store
* @see app/Http/Controllers/ProjectSourceController.php:22
* @route '/projects/{project}/sources'
*/
export const store = (args: { project: string | { id: string } } | [project: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/projects/{project}/sources',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ProjectSourceController::store
* @see app/Http/Controllers/ProjectSourceController.php:22
* @route '/projects/{project}/sources'
*/
store.url = (args: { project: string | { id: string } } | [project: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { project: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { project: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            project: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        project: typeof args.project === 'object'
        ? args.project.id
        : args.project,
    }

    return store.definition.url
            .replace('{project}', parsedArgs.project.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProjectSourceController::store
* @see app/Http/Controllers/ProjectSourceController.php:22
* @route '/projects/{project}/sources'
*/
store.post = (args: { project: string | { id: string } } | [project: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\ProjectSourceController::store
* @see app/Http/Controllers/ProjectSourceController.php:22
* @route '/projects/{project}/sources'
*/
const storeForm = (args: { project: string | { id: string } } | [project: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\ProjectSourceController::store
* @see app/Http/Controllers/ProjectSourceController.php:22
* @route '/projects/{project}/sources'
*/
storeForm.post = (args: { project: string | { id: string } } | [project: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\ProjectSourceController::show
* @see app/Http/Controllers/ProjectSourceController.php:41
* @route '/projects/{project}/sources/{source}'
*/
export const show = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/projects/{project}/sources/{source}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ProjectSourceController::show
* @see app/Http/Controllers/ProjectSourceController.php:41
* @route '/projects/{project}/sources/{source}'
*/
show.url = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{project}', parsedArgs.project.toString())
            .replace('{source}', parsedArgs.source.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProjectSourceController::show
* @see app/Http/Controllers/ProjectSourceController.php:41
* @route '/projects/{project}/sources/{source}'
*/
show.get = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ProjectSourceController::show
* @see app/Http/Controllers/ProjectSourceController.php:41
* @route '/projects/{project}/sources/{source}'
*/
show.head = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\ProjectSourceController::show
* @see app/Http/Controllers/ProjectSourceController.php:41
* @route '/projects/{project}/sources/{source}'
*/
const showForm = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ProjectSourceController::show
* @see app/Http/Controllers/ProjectSourceController.php:41
* @route '/projects/{project}/sources/{source}'
*/
showForm.get = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ProjectSourceController::show
* @see app/Http/Controllers/ProjectSourceController.php:41
* @route '/projects/{project}/sources/{source}'
*/
showForm.head = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

/**
* @see \App\Http\Controllers\ProjectSourceController::retrySetup
* @see app/Http/Controllers/ProjectSourceController.php:56
* @route '/projects/{project}/sources/{source}/retry-setup'
*/
export const retrySetup = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retrySetup.url(args, options),
    method: 'post',
})

retrySetup.definition = {
    methods: ["post"],
    url: '/projects/{project}/sources/{source}/retry-setup',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ProjectSourceController::retrySetup
* @see app/Http/Controllers/ProjectSourceController.php:56
* @route '/projects/{project}/sources/{source}/retry-setup'
*/
retrySetup.url = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions) => {
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

    return retrySetup.definition.url
            .replace('{project}', parsedArgs.project.toString())
            .replace('{source}', parsedArgs.source.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProjectSourceController::retrySetup
* @see app/Http/Controllers/ProjectSourceController.php:56
* @route '/projects/{project}/sources/{source}/retry-setup'
*/
retrySetup.post = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retrySetup.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\ProjectSourceController::retrySetup
* @see app/Http/Controllers/ProjectSourceController.php:56
* @route '/projects/{project}/sources/{source}/retry-setup'
*/
const retrySetupForm = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: retrySetup.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\ProjectSourceController::retrySetup
* @see app/Http/Controllers/ProjectSourceController.php:56
* @route '/projects/{project}/sources/{source}/retry-setup'
*/
retrySetupForm.post = (args: { project: string | { id: string }, source: string | { id: string } } | [project: string | { id: string }, source: string | { id: string } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: retrySetup.url(args, options),
    method: 'post',
})

retrySetup.form = retrySetupForm

const ProjectSourceController = { store, show, retrySetup }

export default ProjectSourceController