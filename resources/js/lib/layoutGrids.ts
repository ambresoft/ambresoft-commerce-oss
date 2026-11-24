import type { Grid } from '../types/layouts';

/** Immediate editor feedback; the server validates the complete structure again before saving. */
export function validLayoutGrids(grids: Grid[]): boolean {
  if (
    grids.length > 100 ||
    new Set(grids.map((g) => g.id)).size !== grids.length
  )
    return false;
  return grids.every((grid) => {
    const parent = grids.find((g) => g.id === grid.parent_id);
    if (grid.parent_id !== null && !parent) return false;
    if (parent && parent.region !== grid.region) return false;
    const capacity = parent?.width ?? 16;
    if (
      ![grid.width, grid.row, grid.position].every(Number.isInteger) ||
      grid.width < 1 ||
      grid.width > capacity ||
      grid.row < 1 ||
      grid.position < 1
    )
      return false;
    const seen = [grid.id];
    let node = parent;
    while (node) {
      if (seen.includes(node.id) || seen.length >= 8) return false;
      seen.push(node.id);
      node = grids.find((g) => g.id === node?.parent_id);
    }
    const siblings = grids.filter(
      (g) =>
        g.parent_id === grid.parent_id &&
        g.region === grid.region &&
        g.row === grid.row,
    );
    return (
      siblings.reduce((sum, g) => sum + g.width, 0) <= capacity &&
      siblings.filter((g) => g.position === grid.position).length === 1
    );
  });
}
