import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { ArrowLeft } from 'lucide-react';

interface User {
    id: number;
    name: string;
    email: string;
}

interface Props {
    clients: User[];
}

export default function ProjectCreate({ clients }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        status: 'active',
        client_ids: [] as number[],
    });

    const [selectedClients, setSelectedClients] = useState<number[]>([]);

    const handleClientToggle = (clientId: number) => {
        const newSelectedClients = selectedClients.includes(clientId)
            ? selectedClients.filter(id => id !== clientId)
            : [...selectedClients, clientId];
        
        setSelectedClients(newSelectedClients);
        setData('client_ids', newSelectedClients);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        
        // Client-side validation
        if (!data.name.trim()) {
            alert('Project name is required');
            return;
        }
        
        if (selectedClients.length === 0) {
            alert('At least one client must be assigned to the project');
            return;
        }
        
        post('/projects');
    };

    return (
        <AppLayout>
            <Head title="Create Project" />
            
            <div className="max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div className="p-6">
                        <div className="flex items-center gap-4 mb-6">
                            <Link href="/projects">
                                <Button variant="outline" size="sm">
                                    <ArrowLeft className="w-4 h-4" />
                                </Button>
                            </Link>
                            <h1 className="text-2xl font-semibold text-gray-900">Create Project</h1>
                        </div>

                        <form onSubmit={submit}>
                            <div className="space-y-6">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Project Details</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div>
                                            <Label htmlFor="name">Project Name *</Label>
                                            <Input
                                                id="name"
                                                type="text"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                placeholder="Enter project name"
                                                className="mt-1"
                                                required
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
                                            <Label htmlFor="status">Status *</Label>
                                            <Select value={data.status} onValueChange={(value) => setData('status', value)} required>
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
                                        <CardTitle>Assign Clients *</CardTitle>
                                        <p className="text-sm text-gray-600">
                                            Select which clients should have access to this project
                                        </p>
                                    </CardHeader>
                                    <CardContent>
                                        {clients.length === 0 ? (
                                            <p className="text-gray-500">No clients available</p>
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
                                        {processing ? 'Creating...' : 'Create Project'}
                                    </Button>
                                    <Link href="/projects">
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
        </AppLayout>
    );
}