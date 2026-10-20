import AgentSessionController from './AgentSessionController'
import ProjectController from './ProjectController'
import ProjectSourceController from './ProjectSourceController'
import AuditRunController from './AuditRunController'
import Settings from './Settings'

const Controllers = {
    AgentSessionController: Object.assign(AgentSessionController, AgentSessionController),
    ProjectController: Object.assign(ProjectController, ProjectController),
    ProjectSourceController: Object.assign(ProjectSourceController, ProjectSourceController),
    AuditRunController: Object.assign(AuditRunController, AuditRunController),
    Settings: Object.assign(Settings, Settings),
}

export default Controllers