import {
    type ColumnDef,
    type RowSelectionState,
    type VisibilityState,
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    type OnChangeFn,
    useReactTable
} from '@tanstack/react-table';
import { Columns2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

interface DataTableProps<TData, TValue> {
    columns: ColumnDef<TData, TValue>[];
    data: TData[];
    getRowId?: (originalRow: TData, index: number) => string;
    /** Column ids (left-to-right) pinned to the left during horizontal scroll */
    stickyLeftColumnIds?: string[];
    columnVisibility?: VisibilityState;
    onColumnVisibilityChange?: OnChangeFn<VisibilityState>;
    enableColumnPicker?: boolean;
    columnPickerTitle?: string;
    columnGroups?: { label: string; columnIds: string[] }[];
    enableRowSelection?: boolean;
    rowSelection?: RowSelectionState;
    onRowSelectionChange?: OnChangeFn<RowSelectionState>;
}

function stickyCellClass(
    columnId: string,
    stickyLeftColumnIds: string[] | undefined,
    variant: 'head' | 'body',
): string | undefined {
    if (!stickyLeftColumnIds?.length) {
        return undefined;
    }
    const idx = stickyLeftColumnIds.indexOf(columnId);
    if (idx === -1) {
        return undefined;
    }
    const leftClass =
        idx === 0 ? 'left-0' : idx === 1 ? 'left-14' : idx === 2 ? 'left-28' : undefined;
    const z = variant === 'head' ? 'z-30' : 'z-20';
    const bg = variant === 'head' ? 'bg-muted' : 'bg-background';
    const isSelectColumn = columnId === 'select';
    return cn(
        'sticky border-r border-border shadow-[2px_0_4px_-2px_rgba(0,0,0,0.06)] dark:shadow-[2px_0_4px_-2px_rgba(0,0,0,0.25)]',
        leftClass,
        isSelectColumn && 'w-14 min-w-14 max-w-14 px-1',
        z,
        bg,
        variant === 'body' &&
            'group-hover:bg-muted/50 group-data-[state=selected]:bg-muted',
    );
}

export function DataTable<TData, TValue>({
    columns,
    data,
    getRowId,
    stickyLeftColumnIds,
    columnVisibility: controlledColumnVisibility,
    onColumnVisibilityChange,
    enableColumnPicker = false,
    columnPickerTitle = 'Columns',
    columnGroups,
    enableRowSelection = false,
    rowSelection,
    onRowSelectionChange,
}: DataTableProps<TData, TValue>) {
    const [internalColumnVisibility, setInternalColumnVisibility] = useState<VisibilityState>(
        controlledColumnVisibility ?? {},
    );
    const isControlled = controlledColumnVisibility !== undefined;
    const columnVisibility = isControlled
        ? controlledColumnVisibility
        : internalColumnVisibility;

    useEffect(() => {
        if (!isControlled) {
            setInternalColumnVisibility(controlledColumnVisibility ?? {});
        }
    }, [controlledColumnVisibility, isControlled]);

    const handleColumnVisibilityChange: OnChangeFn<VisibilityState> = (updater) => {
        if (isControlled) {
            onColumnVisibilityChange?.(updater);
            return;
        }
        setInternalColumnVisibility((current) => {
            const next =
                typeof updater === 'function'
                    ? updater(current)
                    : updater;
            onColumnVisibilityChange?.(next);
            return next;
        });
    };

    const table = useReactTable({
        data,
        columns,
        getCoreRowModel: getCoreRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        enableRowSelection,
        state: {
            columnVisibility,
            ...(enableRowSelection && rowSelection !== undefined
                ? { rowSelection }
                : {}),
        },
        onColumnVisibilityChange: handleColumnVisibilityChange,
        ...(enableRowSelection && onRowSelectionChange
            ? { onRowSelectionChange }
            : {}),
        ...(getRowId ? { getRowId } : {}),
    })

    const hideableColumns = useMemo(
        () => table.getAllLeafColumns().filter((column) => column.getCanHide()),
        [table],
    );

    const groupedColumns = useMemo(() => {
        if (!columnGroups?.length) {
            return [];
        }
        return columnGroups
            .map((group) => {
                const cols = group.columnIds
                    .map((id) => table.getColumn(id))
                    .filter((column): column is NonNullable<typeof column> =>
                        Boolean(column?.getCanHide()));
                return { label: group.label, columns: cols };
            })
            .filter((group) => group.columns.length > 0);
    }, [columnGroups, table]);

    return (
        <div className="space-y-2">
            {enableColumnPicker ? (
                <div className="flex items-center justify-end">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button type="button" variant="outline" size="sm" className="gap-2">
                                <Columns2 className="size-4" aria-hidden />
                                {columnPickerTitle}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="max-h-[60vh] w-64 overflow-y-auto">
                            {groupedColumns.length > 0 ? (
                                groupedColumns.map((group, index) => (
                                    <div key={group.label}>
                                        {index > 0 ? <DropdownMenuSeparator /> : null}
                                        <DropdownMenuLabel>{group.label}</DropdownMenuLabel>
                                        {group.columns.map((column) => (
                                            <DropdownMenuCheckboxItem
                                                key={column.id}
                                                checked={column.getIsVisible()}
                                                onCheckedChange={(value) =>
                                                    column.toggleVisibility(Boolean(value))}
                                            >
                                                {String(column.columnDef.header ?? column.id)}
                                            </DropdownMenuCheckboxItem>
                                        ))}
                                    </div>
                                ))
                            ) : (
                                hideableColumns.map((column) => (
                                    <DropdownMenuCheckboxItem
                                        key={column.id}
                                        checked={column.getIsVisible()}
                                        onCheckedChange={(value) =>
                                            column.toggleVisibility(Boolean(value))}
                                    >
                                        {String(column.columnDef.header ?? column.id)}
                                    </DropdownMenuCheckboxItem>
                                ))
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            ) : null}
            <div className="min-w-0 overflow-x-auto rounded-md border">
            <Table
                className={
                    stickyLeftColumnIds?.length
                        ? 'border-separate border-spacing-0'
                        : undefined
                }
            >
                <TableHeader>
                    {table.getHeaderGroups().map(headerGroup => (
                        <TableRow key={headerGroup.id}>
                            {headerGroup.headers.map((header) => {
                                return (
                                    <TableHead
                                        key={header.id}
                                        className={stickyCellClass(
                                            header.column.id,
                                            stickyLeftColumnIds,
                                            'head',
                                        )}
                                    >
                                        {header.isPlaceholder
                                        ? null
                                        : flexRender(
                                            header.column.columnDef.header,
                                                header.getContext()
                                            )}
                                    </TableHead>
                                )
                                }
                            )}
                        </TableRow>
                    ))}
                </TableHeader>
                <TableBody>
                    {table.getRowModel().rows?.length ? (
                        table.getRowModel().rows.map((row) => (
                            <TableRow
                            key={row.id}
                            className="group"
                            data-state={row.getIsSelected() && 'selected'}
                            >
                                {row.getVisibleCells().map((cell) => (
                                    <TableCell
                                        key={cell.id}
                                        className={stickyCellClass(
                                            cell.column.id,
                                            stickyLeftColumnIds,
                                            'body',
                                        )}
                                    >
                                        {flexRender(
                                            cell.column.columnDef.cell,
                                            cell.getContext()
                                        )}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))
                    ) : (
                        <TableRow>
                            <TableCell colSpan={columns.length} className="h-24 text-center">
                                No results.
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>
            </div>
        </div>
    )
}
