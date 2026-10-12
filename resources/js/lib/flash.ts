export interface FlashMessage {
    id: string;
    type: 'success' | 'error';
    message: string;
}

type Listener = (messages: FlashMessage[]) => void;

// Kept outside React so a message raised just before moving to another page is still shown there
let messages: FlashMessage[] = [];
const listeners = new Set<Listener>();
const seenIds = new Set<string>();
let counter = 0;

function emit() {
    listeners.forEach((listener) => listener(messages));
}

/** Show a message. Passing the same id twice shows it only once. */
export function flash(
    type: FlashMessage['type'],
    message: string,
    id?: string,
): void {
    const messageId = id ?? `local-${Date.now()}-${(counter += 1)}`;
    if (!message || seenIds.has(messageId)) return;

    seenIds.add(messageId);
    messages = [...messages, { id: messageId, type, message }];
    emit();
}

/** Show a success message after an action has actually succeeded. */
export function flashSuccess(
    message: string | undefined | null,
    fallback = 'Saved successfully.',
): void {
    flash('success', message || fallback);
}

export function flashError(
    message: string | undefined | null,
    fallback = 'Something went wrong.',
): void {
    flash('error', message || fallback);
}

export function dismissFlash(id: string): void {
    messages = messages.filter((item) => item.id !== id);
    emit();
}

export function subscribeToFlash(listener: Listener): () => void {
    listeners.add(listener);
    listener(messages);

    return () => {
        listeners.delete(listener);
    };
}
