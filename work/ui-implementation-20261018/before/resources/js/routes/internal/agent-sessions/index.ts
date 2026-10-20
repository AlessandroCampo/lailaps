import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\AgentSessionController::show
* @see app/Http/Controllers/AgentSessionController.php:12
* @route '/api/internal/agent-sessions/{agentSession}'
*/
export const show = (args: { agentSession: string | { id: string } } | [agentSession: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/internal/agent-sessions/{agentSession}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentSessionController::show
* @see app/Http/Controllers/AgentSessionController.php:12
* @route '/api/internal/agent-sessions/{agentSession}'
*/
show.url = (args: { agentSession: string | { id: string } } | [agentSession: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { agentSession: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { agentSession: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            agentSession: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        agentSession: typeof args.agentSession === 'object'
        ? args.agentSession.id
        : args.agentSession,
    }

    return show.definition.url
            .replace('{agentSession}', parsedArgs.agentSession.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentSessionController::show
* @see app/Http/Controllers/AgentSessionController.php:12
* @route '/api/internal/agent-sessions/{agentSession}'
*/
show.get = (args: { agentSession: string | { id: string } } | [agentSession: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentSessionController::show
* @see app/Http/Controllers/AgentSessionController.php:12
* @route '/api/internal/agent-sessions/{agentSession}'
*/
show.head = (args: { agentSession: string | { id: string } } | [agentSession: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentSessionController::show
* @see app/Http/Controllers/AgentSessionController.php:12
* @route '/api/internal/agent-sessions/{agentSession}'
*/
const showForm = (args: { agentSession: string | { id: string } } | [agentSession: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentSessionController::show
* @see app/Http/Controllers/AgentSessionController.php:12
* @route '/api/internal/agent-sessions/{agentSession}'
*/
showForm.get = (args: { agentSession: string | { id: string } } | [agentSession: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentSessionController::show
* @see app/Http/Controllers/AgentSessionController.php:12
* @route '/api/internal/agent-sessions/{agentSession}'
*/
showForm.head = (args: { agentSession: string | { id: string } } | [agentSession: string | { id: string } ] | string | { id: string }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

const agentSessions = {
    show: Object.assign(show, show),
}

export default agentSessions