import {
    type ColumnDef,
    flexRender,
    getCoreRowModel,
    useReactTable
} from '@tanstack/react-table';

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
        idx === 0 ? 'left-0' : idx === 1 ? 'left-14' : `left-[${idx * 3.5}rem]`;
    const z = variant === 'head' ? 'z-30' : 'z-20';
    const bg = variant === 'head' ? 'bg-muted' : 'bg-background';
    return cn(
        'sticky border-r border-border shadow-[2px_0_4px_-2px_rgba(0,0,0,0.06)] dark:shadow-[2px_0_4px_-2px_rgba(0,0,0,0.25)]',
        leftClass,
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
}: DataTableProps<TData, TValue>) {
    const table = useReactTable({
        data,
        columns,
        getCoreRowModel: getCoreRowModel(),
        ...(getRowId ? { getRowId } : {}),
    })

    return (
        <div className="overflow-hidden rounded-md border">
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
    )
}
