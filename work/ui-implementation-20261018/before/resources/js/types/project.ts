export type Project = {
    id: string;
    name: string;
    created_at: string;
};

export type ProjectSource = {
    id: string;
    source_type: 'zip' | 'github';
    status: 'pending' | 'importing' | 'ready' | 'failed';
    error: string | null;
    uploaded_at: string | null;
    created_at: string;
};

export type ProjectSummary = Project & { sources_count: number };

export type ProjectPreparationStatus = 'running' | 'failed' | 'ready';

export type PaginatedProjects = {
    data: ProjectSummary[];
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
