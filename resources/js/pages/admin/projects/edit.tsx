import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState, useEffect } from 'react';
import { getCsrfToken } from '@/lib/csrf';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ArrowLeft, AlertTriangle } from 'lucide-react';

interface User {
    id: number;
    name: string;
    email: string;
}

interface Project {
    id: number;
    uuid: string;
    name: string;
    description: string;
    status: 'active' | 'inactive' | 'completed';
    users: User[];
}

interface Props {
    project: Project;
    clients: User[];
}

export default function ProjectEdit({ project, clients }: Props) {
    const { data, setData, patch, processing, errors } = useForm({
        name: project.name,
        description: project.description || '',
        status: project.status,
        client_ids: project.users.map(user => user.id),
    });

    const [selectedClients, setSelectedClients] = useState<number[]>(
        project.users.map(user => user.id)
    );
    const [showConfirmation, setShowConfirmation] = useState(false);
    const [pendingFormData, setPendingFormData] = useState<any>(null);

    const handleClientToggle = (clientId: number) => {
        const newSelectedClients = selectedClients.includes(clientId)
            ? selectedClients.filter(id => id !== clientId)
            : [...selectedClients, clientId];
        
        setSelectedClients(newSelectedClients);
        setData('client_ids', newSelectedClients);
    };

    const submit: FormEventHandler = async (e) => {
        e.preventDefault();
        
        // Check if status is being changed to completed
        if (data.status === 'completed' && project.status !== 'completed') {
            // Show confirmation dialog
            setShowConfirmation(true);
            return;
        }
        
        // Proceed with normal submission
        await performSubmission();
    };

    const performSubmission = async () => {
        try {
            // Refresh CSRF token before submission to prevent session expiry issues
            const tokenResponse = await fetch('/api/csrf-token', {
                credentials: 'same-origin'
            });
            
            if (tokenResponse.ok) {
                const tokenData = await tokenResponse.json();
                // Update meta tag with fresh token
                const metaTag = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement;
                if (metaTag) {
                    metaTag.content = tokenData.token;
                }
            }
        } catch (error) {
            console.warn('Could not refresh CSRF token:', error);
        }
        
        patch(`/projects/${project.uuid}/update`);
    };

    const handleConfirmCompletion = () => {
        setShowConfirmation(false);
        performSubmission();
    };

    const handleCancelCompletion = () => {
        setShowConfirmation(false);
        // Reset status back to original
        setData('status', project.status);
    };

    return (
        <AppLayout>
            <Head title={`Edit Project: ${project.name}`} />
            
            <div className="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div className="bg-card overflow-hidden shadow-sm sm:rounded-lg">
                    <div className="p-6">
                        <div className="flex items-center gap-4 mb-6">
                            <Link href={`/projects/view/${project.uuid}`}>
                                <Button variant="outline" size="sm">
                                    <ArrowLeft className="w-4 h-4" />
                                </Button>
                            </Link>
                            <h1 className="text-3xl font-extrabold tracking-tight">Edit Project: {project.name}</h1>
                        </div>

                        <form onSubmit={submit}>
                            <div className="space-y-6">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Project Details</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div>
                                            <Label htmlFor="name">Project Name</Label>
                                            <Input
                                                id="name"
                                                type="text"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                placeholder="Enter project name"
                                                className="mt-1"
                                            />
                                            {errors.name && <p className="text-red-600 text-sm mt-1">{errors.name}</p>}
                                        </div>

                                        <div>
                                            <Label htmlFor="description">Description</Label>
                                            <Textarea
                                                id="description"
                                                value={data.description}
                                                onChange={(e) => setData('description', e.target.value)}
                                                placeholder="Enter project description (optional)"
                                                className="mt-1"
                                                rows={3}
                                            />
                                            {errors.description && <p className="text-red-600 text-sm mt-1">{errors.description}</p>}
                                        </div>

                                        <div>
                                            <Label htmlFor="status">Status</Label>
                                            <Select value={data.status} onValueChange={(value) => setData('status', value)}>
                                                <SelectTrigger className="mt-1">
                                                    <SelectValue placeholder="Select status" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="active">Active</SelectItem>
                                                    <SelectItem value="inactive">Inactive</SelectItem>
                                                    <SelectItem value="completed">Completed</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {errors.status && <p className="text-red-600 text-sm mt-1">{errors.status}</p>}
                                        </div>

                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Team Members</CardTitle>
                                        <p className="text-sm text-gray-600">
                                            Select the staff of this company who should work on this project
                                        </p>
                                    </CardHeader>
                                    <CardContent>
                                        {clients.length === 0 ? (
                                            <p className="text-gray-500">This company has no active staff yet</p>
                                        ) : (
                                            <div className="space-y-3 max-h-64 overflow-y-auto">
                                                {clients.map((client) => (
                                                    <div key={client.id} className="flex items-center space-x-3">
                                                        <Checkbox
                                                            id={`client-${client.id}`}
                                                            checked={selectedClients.includes(client.id)}
                                                            onCheckedChange={() => handleClientToggle(client.id)}
                                                        />
                                                        <Label 
                                                            htmlFor={`client-${client.id}`}
                                                            className="flex-1 cursor-pointer"
                                                        >
                                                            <div>
                                                                <p className="font-medium">{client.name}</p>
                                                                <p className="text-sm text-gray-500">{client.email}</p>
                                                            </div>
                                                        </Label>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                        {errors.client_ids && <p className="text-red-600 text-sm mt-2">{errors.client_ids}</p>}
                                    </CardContent>
                                </Card>

                                <div className="flex items-center gap-4">
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Updating...' : 'Update Project'}
                                    </Button>
                                    <Link href={`/projects/view/${project.uuid}`}>
                                        <Button variant="outline" type="button">
                                            Cancel
                                        </Button>
                                    </Link>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {/* Confirmation Dialog */}
            <Dialog open={showConfirmation} onOpenChange={setShowConfirmation}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-amber-600">
                            <AlertTriangle className="h-5 w-5" />
                            Mark Project as Completed?
                        </DialogTitle>
                        <DialogDescription className="space-y-3 text-gray-600">
                            <p>
                                Are you sure you want to mark this project as <strong>completed</strong>?
                            </p>
                            <div className="bg-amber-50 border border-amber-200 rounded-lg p-3">
                                <p className="text-sm text-amber-800 font-medium">
                                    ⚠️ This action will automatically close all unfinished issues in this project:
                                </p>
                                <ul className="text-sm text-amber-700 mt-2 space-y-1">
                                    <li>• Issues with status "Open"</li>
                                    <li>• Issues with status "Reopened"</li>
                                    <li>• Issues with status "In Progress"</li>
                                </ul>
                                <p className="text-xs text-amber-600 mt-2">
                                    Issues that are already "Resolved" or "Closed" will remain unchanged.
                                </p>
                            </div>
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="flex-col sm:flex-row gap-2">
                        <Button
                            variant="outline"
                            onClick={handleCancelCompletion}
                            className="w-full sm:w-auto"
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={handleConfirmCompletion}
                            className="w-full sm:w-auto bg-amber-600 hover:bg-amber-700"
                        >
                            Yes, Mark as Completed
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}