import {
    getInstallState,
    isOnline,
    subscribeToConnection,
    subscribeToInstallState,
    type InstallState,
} from '@/lib/pwa';
import { useSyncExternalStore } from 'react';

const NOT_INSTALLABLE: InstallState = {
    canPrompt: false,
    needsManualSteps: false,
    installed: false,
};

export function useInstallState(): InstallState {
    return useSyncExternalStore(
        subscribeToInstallState,
        getInstallState,
        () => NOT_INSTALLABLE,
    );
}

export function useOnline(): boolean {
    return useSyncExternalStore(subscribeToConnection, isOnline, () => true);
}
