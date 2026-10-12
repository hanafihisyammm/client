import { getCsrfToken } from '@/lib/csrf';
import { flashError, flashSuccess } from '@/lib/flash';
import { type DragEvent, useState } from 'react';

export type IssueStatus = 'open' | 'in_progress' | 'resolved' | 'closed' | 'reopened';

/**
 * Which statuses an issue may move to from each status. Must match
 * Report::STATUS_TRANSITIONS on the server, which enforces it.
 */
export const STATUS_TRANSITIONS: Record<IssueStatus, IssueStatus[]> = {
    open: ['in_progress', 'closed'],
    in_progress: ['resolved', 'open'],
    resolved: ['closed', 'reopened', 'in_progress'],
    closed: ['reopened'],
    reopened: ['in_progress', 'closed'],
};

interface DraggedIssue {
    uuid: string;
    status: IssueStatus;
}

async function moveIssue(uuid: string, newStatus: IssueStatus): Promise<string | null> {
    // Ask for a fresh token first, as the other issue actions do, so a long-open board still works
    let token = getCsrfToken() ?? '';
    try {
        const tokenResponse = await fetch('/api/csrf-token', { credentials: 'same-origin' });
        if (tokenResponse.ok) token = (await tokenResponse.json()).token;
    } catch {
        // Fall back to the token already on the page
    }

    try {
        const response = await fetch(`/api/reports/${uuid}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ action: 'transition', new_status: newStatus }),
        });

        const result = await response.json().catch(() => null);

        if (response.ok) {
            flashSuccess(result?.message, 'Issue status updated.');
            return null;
        }

        return result?.message ?? 'The issue could not be moved.';
    } catch {
        return 'Network error. The issue was not moved.';
    }
}

/**
 * Drag an issue card from one status column to another to change its status.
 * Columns the issue cannot move to are marked while dragging and refuse the drop.
 */
export function useIssueDrag({ enabled, onMoved }: { enabled: boolean; onMoved: () => void }) {
    const [dragged, setDragged] = useState<DraggedIssue | null>(null);
    const [overStatus, setOverStatus] = useState<IssueStatus | null>(null);
    const [movingUuid, setMovingUuid] = useState<string | null>(null);

    const canDropOn = (status: IssueStatus) => !!dragged && STATUS_TRANSITIONS[dragged.status].includes(status);

    const cardProps = (issue: { uuid: string; status: IssueStatus }) =>
        enabled
            ? {
                  draggable: true,
                  onDragStart: (event: DragEvent<HTMLElement>) => {
                      event.dataTransfer.effectAllowed = 'move';
                      event.dataTransfer.setData('text/plain', issue.uuid);
                      setDragged({ uuid: issue.uuid, status: issue.status });
                  },
                  onDragEnd: () => {
                      setDragged(null);
                      setOverStatus(null);
                  },
              }
            : {};

    const columnProps = (status: IssueStatus) =>
        enabled
            ? {
                  onDragOver: (event: DragEvent<HTMLElement>) => {
                      if (!canDropOn(status)) return;
                      event.preventDefault();
                      event.dataTransfer.dropEffect = 'move';
                      if (overStatus !== status) setOverStatus(status);
                  },
                  onDragLeave: (event: DragEvent<HTMLElement>) => {
                      if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setOverStatus(null);
                  },
                  onDrop: async (event: DragEvent<HTMLElement>) => {
                      event.preventDefault();
                      const issue = dragged;
                      setDragged(null);
                      setOverStatus(null);
                      if (!issue || !STATUS_TRANSITIONS[issue.status].includes(status)) return;

                      setMovingUuid(issue.uuid);
                      const failure = await moveIssue(issue.uuid, status);
                      setMovingUuid(null);

                      if (failure) {
                          flashError(failure);
                      } else {
                          onMoved();
                      }
                  },
              }
            : {};

    /** How a column should look right now: a valid target, the one hovered, blocked, or nothing special. */
    const columnState = (status: IssueStatus): 'idle' | 'target' | 'over' | 'blocked' => {
        if (!dragged || dragged.status === status) return 'idle';
        if (!canDropOn(status)) return 'blocked';
        return overStatus === status ? 'over' : 'target';
    };

    return { cardProps, columnProps, columnState, movingUuid, enabled };
}

/** Classes for a column while an issue is being dragged. */
export function columnDragClass(state: 'idle' | 'target' | 'over' | 'blocked'): string {
    switch (state) {
        case 'over':
            return 'outline-2 -outline-offset-2 outline-brand-gold bg-brand-cream/60';
        case 'target':
            return 'outline-2 -outline-offset-2 outline-dashed outline-brand-gold/60';
        case 'blocked':
            return 'opacity-40';
        default:
            return '';
    }
}
